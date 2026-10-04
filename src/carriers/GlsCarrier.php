<?php

namespace justinholtweb\carriergls\carriers;

use Craft;
use DateTime;
use DateTimeZone;
use justinholtweb\carrier\base\Accessorial;
use justinholtweb\carrier\base\ActionResult;
use justinholtweb\carrier\base\AuthInterface;
use justinholtweb\carrier\base\Capabilities;
use justinholtweb\carrier\base\Carrier;
use justinholtweb\carrier\base\Feature;
use justinholtweb\carrier\base\Field;
use justinholtweb\carrier\base\HealthResult;
use justinholtweb\carrier\base\LabelResult;
use justinholtweb\carrier\base\PointPage;
use justinholtweb\carrier\base\Response;
use justinholtweb\carrier\base\ShipmentResult;
use justinholtweb\carrier\base\TrackingStatus;
use justinholtweb\carrier\base\Transport;
use justinholtweb\carrier\helpers\Addresses;
use justinholtweb\carrier\helpers\LabelFormat;
use justinholtweb\carrier\helpers\Units;
use justinholtweb\carrier\models\canonical\Address;
use justinholtweb\carrier\models\canonical\Label;
use justinholtweb\carrier\models\canonical\PickupPoint;
use justinholtweb\carrier\models\canonical\PointQuery;
use justinholtweb\carrier\models\canonical\ShipmentRef;
use justinholtweb\carrier\models\canonical\ShipmentRequest;
use justinholtweb\carrier\models\canonical\TrackingEvent;
use justinholtweb\carrier\models\canonical\TrackingInfo;
use justinholtweb\carriergls\auth\MyGlsAuth;

/**
 * GLS through the MyGLS API (Croatia, Hungary, Czechia, Romania, Slovenia, Slovakia, Serbia).
 *
 * Written against *MyGLS API for system integration* ver. 25.12.11 (change log to 2026-03-30), the
 * official PHP REST sample in mygls_api_20260331.zip, and webapix/mygls-sdk. No live account.
 *
 * ## How it maps
 *
 * - One consignment is one MyGLS `Parcel` with `Count` = number of pieces and one
 *   `ParcelPropertyList` row per piece. `PrintLabels` creates it and returns the label in the same
 *   call, so there is never a created-but-unlabelled window to clean up.
 * - Every parcel has **two ids**: `ParcelId` (MyGLS's database id, needed to reprint and delete)
 *   and `ParcelNumber` (the tracking number). Both go in `ShipmentResult::$data`.
 * - Reprint is `GetPrintedLabels` (≤ 99 ParcelIds → one document), which is how Carrier's bulk
 *   print gets A4 sheets of four. Void is `DeleteLabels` (≤ 50 ParcelIds).
 * - Tracking is `GetParcelListStatuses` (≤ 100 numbers, added 2026-03), falling back to one
 *   `GetParcelStatuses` per number when a MyGLS host answers 404 for the batch method.
 * - Points come from the public map feed (see below). There are no rates, pickups or manifests in
 *   MyGLS: the contract's daily collection picks up, and `PickupDate` says when.
 *
 * ## Vendor traps
 *
 * - **HTTP 200 for everything, including a bad password.** Each method has its *own* error-list
 *   key (`PrintLabelsErrorList`, `GetPrintedLabelsErrorList`, `DeleteLabelsErrorList`,
 *   `GetParcelListStatusesErrors`, `GetParcelStatusErrors`, `GetParcelListErrors`), holding
 *   `ErrorInfo{ErrorCode, ErrorDescription, ClientReferenceList, ParcelIdList}`. A bad login is
 *   `ErrorCode -1, "Unauthorized."` inside that list. Every call here reads its own key; an error
 *   list on `PrintLabels` is a refusal, not a success with warnings.
 * - **Error 31, "same request sent 5 times within the last 5 minutes"**, means GLS already has
 *   identical requests, so a parcel may already exist. It is reported as *uncertain*, never as a
 *   refusal a retry could act on. So are 1000/1001 (internal errors) and any error list that came
 *   back next to created parcels (MyGLS allows partial success).
 * - **Password** is SHA-512 bytes as a JSON array of 64 ints (see `MyGlsAuth`). The digest is
 *   password-equivalent, so `secretValues()` adds it to the log redaction list.
 * - **WCF dates.** MyGLS wants `"\/Date(<unix ms>)\/"`. PHP's default `json_encode()` escapes `/`
 *   as `\/`, which is exactly that, while the core Transport's *log* encoding uses
 *   `JSON_UNESCAPED_SLASHES`. Guzzle's `json` option happens to use default flags today, but this
 *   carrier does not rely on it: every body is encoded here with default flags and sent as a raw
 *   `body` with an explicit `Content-Type`. Responses come back as `"/Date(ms+0200)/"`, which
 *   `Carrier::parseDate()` already understands (ms are UTC; the offset is informational).
 * - **Labels are byte arrays**, a JSON list of ints. An A4 page is about 75 KB, so roughly 300,000
 *   integers in one response. The official sample raises memory to 1 GB and the timeout to 600 s;
 *   this carrier uses a 180 s timeout and decodes in chunks.
 * - **`HouseNumber` is digits only**, and 0 is rejected (error 23). Letters and suffixes go in
 *   `HouseNumberInfo`, along with the address's second line.
 * - **`WebshopEngine` is required on `PrintLabels`** since 2025-02. It is sent as `CraftCommerce`.
 * - **PSD (parcel shop / locker) needs the recipient's name, phone and email** on the delivery
 *   address, or MyGLS refuses it. That is checked before the request.
 * - `ParcelNumber` is a `long`. It is sent as a JSON integer and stored as a string.
 *
 * ## Pickup points
 *
 * Points come from `https://map.gls-croatia.com/data/deliveryPoints/{cc}.json`: no auth, the feed
 * GLS's own checkout widget (`gls-dpm.js`) reads, probed live for all seven countries. It is
 * **public but unofficial and undocumented**, and could change without notice. The official
 * alternative is `MasterDataService.svc/json/GetDeliveryPoints` (added 2026-03): it was not
 * implemented because it could not be verified (the production probe timed out, the test host
 * answered 502, and both the encoding of its gzipped `Data` and which of its ids is the PSD id are
 * unconfirmed). Swap `pickupPoints()` over once it is.
 *
 * ## UNVERIFIED
 *
 * - **Which id goes in `PSDParameter.StringValue`.** This sends the map feed's `id`
 *   (e.g. `HR10000-PARCELLOC101`). Best evidence: the manual's own example `"2351-CSOMAGPONT"` has
 *   the same shape, and it is the id GLS's widget hands back. `GetDeliveryPoints`'s
 *   `Matchcode` / `LegacyId` / `Id` were not compared live.
 * - Whether COD needs `{"Code":"COD"}` in `ServiceList` as well as `CODAmount` (believed not: the
 *   official sample and webapix send `CODAmount` only, which is what this does).
 * - Whether the sub-parcels of a `Count > 1` parcel share one `ParcelId` (DeleteLabels returns a
 *   `SubParcelIdList`, which suggests a parent with children). Every distinct `ParcelId` returned
 *   is stored, and all of them are sent to reprint and delete.
 * - `ParcelPropertyList` dimensions are sent in whole centimetres.
 * - `Thermo` is treated as an A6-ish PDF and `ThermoZPL` as ZPL text in the same `Labels` byte
 *   array. The bytes are sniffed, and the format claimed follows what actually came back.
 * - Whether a reprint still works once GLS has scanned the parcel. Carrier stores the bytes it
 *   got at creation anyway.
 * - Status classification for 8, 9, 24, 54 and 59 is a judgement call (see `statusMap()`).
 * - The map feed's extra pair of times in some `hours` rows (`[1,"08:00","16:00","10:00","14:00"]`)
 *   is presumably a break. It is shown in brackets, not interpreted.
 * - Whether GLS rejects COD to a point that lists neither `acceptsCash` nor `acceptsCard`.
 *   Such points are imported with `codAllowed = false`.
 * - The public tracking URL format.
 */
class GlsCarrier extends Carrier
{
    /** The MyGLS systems, by the host's country TLD. Each is a separate system with its own logins. */
    public const COUNTRIES = [
        'hr' => 'Croatia',
        'hu' => 'Hungary',
        'cz' => 'Czechia',
        'ro' => 'Romania',
        'si' => 'Slovenia',
        'sk' => 'Slovakia',
        'rs' => 'Serbia',
    ];

    /** `LanguageIsoCode` for tracking texts. MyGLS has no Serbian, so RS reads English. */
    private const LANGUAGES = ['hr' => 'HR', 'hu' => 'HU', 'cz' => 'CS', 'ro' => 'RO', 'si' => 'SL', 'sk' => 'SK', 'rs' => 'EN'];

    private const WEBSHOP_ENGINE = 'CraftCommerce';

    private const MAP_FEED = 'https://map.gls-croatia.com/data/deliveryPoints/%s.json';

    /** Most ParcelIds `GetPrintedLabels` takes in one call. */
    private const PRINT_BATCH = 99;

    /** Most ParcelIds `DeleteLabels` takes in one call. */
    private const DELETE_BATCH = 50;

    /** Error codes after which the parcel may exist even though MyGLS reported an error. */
    private const MAYBE_CREATED = [31, 1000, 1001];

    /** Status codes that say nothing about where the parcel is: COD data, an email notice. */
    private const INFORMATIONAL = ['52', '99'];

    private static ?array $statusMap = null;

    public static function handle(): string
    {
        return 'gls';
    }

    public static function displayName(): string
    {
        return 'GLS';
    }

    public static function region(): string
    {
        return Craft::t('carrier', 'Europe');
    }

    public static function description(): string
    {
        return Craft::t('carrier', 'GLS through MyGLS in Croatia, Hungary, Czechia, Romania, Slovenia, Slovakia and Serbia: labels, cash on delivery, parcel shops and lockers, tracking.');
    }

    public static function setupUrl(): ?string
    {
        return 'https://api.mygls.hr/index_en.html';
    }

    public static function capabilities(): Capabilities
    {
        return Capabilities::make()
            ->supports(
                Feature::LABELS, Feature::CANCEL, Feature::REPRINT, Feature::TRACKING,
                Feature::COD, Feature::INTERNATIONAL, Feature::MULTI_PIECE,
            )
            ->points(Capabilities::POINTS_LIST, PickupPoint::TYPE_LOCKER, PickupPoint::TYPE_SHOP)
            ->labelFormats(LabelFormat::PDF_A4, LabelFormat::PDF_A6, LabelFormat::ZPL)
            ->originCountries('HR', 'HU', 'CZ', 'RO', 'SI', 'SK', 'RS')
            ->accessorials(Accessorial::SATURDAY_DELIVERY, Accessorial::NOTIFY)
            ->trackingBatch(100)
            ->withSandbox();
    }

    public static function settingsFields(): array
    {
        return [
            Field::select('country', 'MyGLS country', self::COUNTRIES, [
                'required' => true,
                'instructions' => 'The GLS country your contract is with. Each country runs its own MyGLS, with its own logins.',
            ]),
            Field::environment([
                'instructions' => 'The MyGLS test system (api.test.mygls.…) needs separate test credentials from your local GLS office.',
            ]),
            Field::text('username', 'MyGLS username', [
                'required' => true,
                'instructions' => 'The email address you sign in to MyGLS with.',
            ]),
            Field::secret('password', 'MyGLS password', [
                'required' => true,
            ]),
            Field::number('clientNumber', 'Client number', [
                'required' => true,
                'min' => 1,
                'instructions' => 'Your GLS client number, from the contract. It goes on every parcel.',
            ]),
            Field::select('a4Layout', 'A4 sheet layout', [
                'A4_2x2' => 'Four labels in a 2 × 2 grid',
                'A4_4x1' => 'Four labels stacked',
            ]),
            Field::select('zplPrinter', 'ZPL printer resolution', [
                'ThermoZPL' => '203 dpi',
                'ThermoZPL_300DPI' => '300 dpi',
            ]),
            Field::boolean('hidePhone', 'Hide phone numbers on labels'),
        ];
    }

    public static function services(): array
    {
        return [
            'parcel' => ['name' => 'GLS BusinessParcel', 'international' => true, 'points' => false],
            'psd' => ['name' => 'GLS ParcelShop / ParcelLocker', 'international' => false, 'points' => true],
        ];
    }

    public static function trackingUrl(string $trackingNumber): ?string
    {
        return 'https://gls-group.com/GROUP/en/parcel-tracking?match=' . rawurlencode($trackingNumber);
    }

    protected function buildAuth(): ?AuthInterface
    {
        return new MyGlsAuth();
    }

    protected function buildTransport(): Transport
    {
        return (new Transport())
            ->setBaseUri(sprintf('https://api.%smygls.%s', $this->isSandbox() ? 'test.' : '', $this->country()))
            ->setDefaultHeaders(['Accept' => 'application/json'])
            // Label responses are byte arrays of several hundred thousand integers.
            ->setTimeout(180);
    }

    /**
     * The SHA-512 digest MyGLS accepts in place of the password is redacted from the log along
     * with the password itself.
     */
    protected function secretValues(): array
    {
        $values = parent::secretValues();
        $password = (string)$this->setting('password', '');

        if ($password !== '') {
            $values[] = (string)json_encode(MyGlsAuth::hashPassword($password));
        }

        return $values;
    }

    protected function probe(): HealthResult
    {
        $zone = new DateTimeZone('Europe/Zagreb');
        $response = $this->call('GetParcelList', [
            'PrintDateFrom' => self::wcfDate(new DateTime('today', $zone)),
            'PrintDateTo' => self::wcfDate(new DateTime('now', $zone)),
        ]);

        if (!$response->ok()) {
            return HealthResult::fail(
                Craft::t('carrier', 'MyGLS did not answer properly: {error}', ['error' => $response->errorMessage()]),
                [Craft::t('carrier', 'Check the country and environment. The test system is api.test.mygls.{cc} and is not always up.', ['cc' => $this->country()])],
            );
        }

        $errors = self::errorList($response, 'GetParcelListErrors');

        if (self::unauthorized($errors)) {
            return HealthResult::fail(
                Craft::t('carrier', 'MyGLS refused the username or password.'),
                [
                    Craft::t('carrier', 'MyGLS logins belong to one country. Check that the country above is the one your account is with.'),
                    Craft::t('carrier', 'The test system and production use different logins.'),
                ],
            );
        }

        if ($errors !== []) {
            return HealthResult::fail(self::describe($errors));
        }

        $printed = $response->at('PrintDataInfoList');

        return HealthResult::pass(
            Craft::t('carrier', 'Connected to MyGLS {country} as {user}.', [
                'country' => self::COUNTRIES[$this->country()],
                'user' => (string)$this->setting('username'),
            ]),
            [
                Craft::t('carrier', 'Client number') => (string)$this->setting('clientNumber'),
                Craft::t('carrier', 'Parcels printed today') => is_array($printed) ? count($printed) : 0,
            ],
        );
    }

    // =========================================================================
    // Labels
    // =========================================================================

    public function createShipment(ShipmentRequest $request): ShipmentResult
    {
        $to = $request->shipTo;
        $from = $request->shipFrom;

        if (!$to || !$from) {
            return ShipmentResult::rejected(Craft::t('carrier', 'GLS needs both a ship-from and a ship-to address.'));
        }

        if ($request->parcels === [] || count($request->parcels) > 99) {
            return ShipmentResult::rejected(Craft::t('carrier', 'A GLS shipment holds between 1 and 99 parcels.'));
        }

        $psd = $request->pointId !== '' || (self::services()[$request->serviceCode]['points'] ?? false);

        if ($psd && $request->pointId === '') {
            return ShipmentResult::rejected(Craft::t('carrier', 'Choose a GLS parcel shop or locker for this shipment.'));
        }

        if ($psd && ($to->contactName() === '' || $to->phone === '' || $to->email === '')) {
            return ShipmentResult::rejected(Craft::t('carrier', 'GLS needs the recipient’s name, phone number and email address to deliver to a parcel shop or locker.'));
        }

        $warnings = [];
        $format = $this->labelFormatFor($request);
        $payload = [
            'WebshopEngine' => self::WEBSHOP_ENGINE,
            'ParcelList' => [$this->parcelPayload($request, $psd, $warnings)],
            'TypeOfPrinter' => $this->printerFor($format),
            'PrintPosition' => 1,
            'ShowPrintDialog' => false,
        ];

        if ($this->boolSetting('hidePhone')) {
            $payload['HidePhoneNumberOnLabels'] = true;
        }

        $response = $this->call('PrintLabels', $payload);

        if (!$response->ok()) {
            return ShipmentResult::fromResponse($response);
        }

        $errors = self::errorList($response, 'PrintLabelsErrorList');
        $infos = self::rows($response->at('PrintLabelsInfoList'));

        if ($errors !== []) {
            $message = $this->failureMessage($errors);

            // Partial success, the duplicate guard or an internal error: a parcel may exist.
            if ($infos !== [] || self::hasCode($errors, self::MAYBE_CREATED)) {
                return ShipmentResult::uncertain($message . ' ' . Craft::t('carrier', 'GLS may have created the parcel anyway. Check MyGLS for reference {ref} before trying again.', ['ref' => $request->reference]));
            }

            return ShipmentResult::rejected($message);
        }

        if ($infos === []) {
            return ShipmentResult::uncertain(Craft::t('carrier', 'MyGLS answered with neither a parcel nor an error. Check MyGLS for reference {ref} before trying again.', ['ref' => $request->reference]));
        }

        $parcelIds = self::uniqueStrings(array_column($infos, 'ParcelId'));
        $numbers = array_map(static fn($n) => self::numberString($n), array_column($infos, 'ParcelNumber'));
        $bytes = self::bytes($response->at('Labels'));

        // Created, but the label or the numbers did not come back with it. Ask once more.
        if ($parcelIds !== [] && ($bytes === '' || $numbers === [] || in_array('', $numbers, true))) {
            $printed = $this->call('GetPrintedLabels', $this->printPayload($parcelIds, $format));

            if ($printed->ok() && self::errorList($printed, 'GetPrintedLabelsErrorList') === []) {
                $bytes = $bytes !== '' ? $bytes : self::bytes($printed->at('Labels'));
                $again = array_map(static fn($n) => self::numberString($n), array_column(self::rows($printed->at('PrintDataInfoList')), 'ParcelNumber'));
                $numbers = $again !== [] && !in_array('', $again, true) ? $again : $numbers;
            }
        }

        $numbers = array_values(array_filter($numbers, static fn(string $n) => $n !== ''));

        if ($bytes === '' || $numbers === []) {
            return ShipmentResult::uncertain(Craft::t('carrier', 'GLS created parcel id {ids} but did not return {missing}. Print or delete it in MyGLS.', [
                'ids' => implode(', ', $parcelIds),
                'missing' => $bytes === '' ? Craft::t('carrier', 'a label') : Craft::t('carrier', 'a parcel number'),
            ]));
        }

        $actual = self::sniff($bytes, $format);
        $single = count($numbers) === 1;
        $result = ShipmentResult::ok($parcelIds[0] ?? $numbers[0], $numbers, [new Label([
            'parcelIndex' => $single ? 0 : null,
            'trackingNumber' => $single ? $numbers[0] : '',
            'format' => $actual,
            'data' => $bytes,
        ])], [
            'parcelIds' => $parcelIds,
            'parcelNumbers' => $numbers,
            'country' => $this->country(),
            'clientReference' => $request->reference,
        ]);

        if (!LabelFormat::bytesMatch($actual, $bytes)) {
            $warnings[] = Craft::t('carrier', 'MyGLS returned a label Carrier does not recognise as {format}.', ['format' => $actual]);
        }

        $result->warnings = $warnings;

        return $result;
    }

    public function cancelShipment(ShipmentRef $shipment): ActionResult
    {
        $ids = self::parcelIdsOf($shipment);

        if ($ids === []) {
            return ActionResult::failed(Craft::t('carrier', 'This shipment has no MyGLS parcel id to delete. Delete it in MyGLS.'));
        }

        foreach (array_chunk($ids, self::DELETE_BATCH) as $chunk) {
            $response = $this->call('DeleteLabels', ['ParcelIdList' => array_map('intval', $chunk)]);

            if (!$response->ok()) {
                return ActionResult::failed($response->errorMessage());
            }

            $errors = self::errorList($response, 'DeleteLabelsErrorList');

            if ($errors !== []) {
                return ActionResult::failed($this->failureMessage($errors) . ' ' . Craft::t('carrier', 'GLS only deletes a parcel it has not scanned yet. Once it has been handed over, call GLS.'));
            }

            $deleted = self::uniqueStrings(array_column(self::rows($response->at('SuccessfullyDeletedList')), 'ParcelId'));
            $missing = array_diff($chunk, $deleted);

            if ($missing !== []) {
                return ActionResult::failed(Craft::t('carrier', 'MyGLS did not confirm deleting parcel id {ids}.', ['ids' => implode(', ', $missing)]));
            }
        }

        return ActionResult::ok(implode(',', $ids), Craft::t('carrier', 'Deleted in MyGLS.'));
    }

    /**
     * One document per 99 parcels — MyGLS lays them out four to a page on the A4 printer types.
     */
    public function printLabels(array $shipments, string $format): LabelResult
    {
        $format = static::capabilities()->supportsLabelFormat($format) ? $format : static::capabilities()->getLabelFormats()[0];
        $ids = [];
        $numbers = [];

        foreach ($shipments as $shipment) {
            array_push($ids, ...self::parcelIdsOf($shipment));
            array_push($numbers, ...$shipment->trackingNumbers);
        }

        $ids = array_values(array_unique($ids));

        if ($ids === []) {
            return LabelResult::failed(Craft::t('carrier', 'No MyGLS parcel ids were stored for these shipments, so GLS cannot reprint them. Print them from MyGLS.'));
        }

        $labels = [];

        foreach (array_chunk($ids, self::PRINT_BATCH) as $chunk) {
            $response = $this->call('GetPrintedLabels', $this->printPayload($chunk, $format));

            if (!$response->ok()) {
                return LabelResult::failed($response->errorMessage());
            }

            $errors = self::errorList($response, 'GetPrintedLabelsErrorList');

            if ($errors !== []) {
                return LabelResult::failed($this->failureMessage($errors));
            }

            $bytes = self::bytes($response->at('Labels'));

            if ($bytes === '') {
                return LabelResult::failed(Craft::t('carrier', 'MyGLS returned no label for parcel id {ids}.', ['ids' => implode(', ', $chunk)]));
            }

            $labels[] = new Label([
                'trackingNumber' => count($numbers) === 1 ? (string)$numbers[0] : '',
                'format' => self::sniff($bytes, $format),
                'data' => $bytes,
            ]);
        }

        return LabelResult::ok($labels);
    }

    // =========================================================================
    // Tracking
    // =========================================================================

    public function track(array $trackingNumbers): array
    {
        $numbers = array_values(array_unique(array_map('strval', $trackingNumbers)));

        if ($numbers === []) {
            return [];
        }

        $response = $this->call('GetParcelListStatuses', [
            'ParcelNumberList' => array_map(self::wireNumber(...), $numbers),
            'LanguageIsoCode' => $this->language(),
        ]);

        // An older MyGLS host without the 2026-03 batch method.
        if ($response->status === 404) {
            return $this->trackOneByOne($numbers);
        }

        if (!$response->ok()) {
            return [];
        }

        if (self::unauthorized(self::errorList($response, 'GetParcelListStatusesErrors'))) {
            $this->note('MyGLS refused the credentials while tracking.');

            return [];
        }

        $byNumber = [];

        foreach (self::rows($response->at('ParcelList')) as $row) {
            $byNumber[self::numberString($row['ParcelNumber'] ?? '')] = self::rows($row['ParcelStatusList'] ?? []);
        }

        $out = [];

        foreach ($numbers as $number) {
            $out[] = isset($byNumber[$number])
                ? self::trackingInfo($number, $byNumber[$number])
                : new TrackingInfo(['trackingNumber' => $number, 'notFound' => true]);
        }

        return $out;
    }

    /**
     * @param string[] $numbers
     * @return TrackingInfo[]
     */
    private function trackOneByOne(array $numbers): array
    {
        $out = [];

        foreach ($numbers as $number) {
            $response = $this->call('GetParcelStatuses', [
                'ParcelNumber' => self::wireNumber($number),
                'ReturnPOD' => false,
                'LanguageIsoCode' => $this->language(),
            ]);

            if (!$response->ok()) {
                continue;
            }

            $errors = self::errorList($response, 'GetParcelStatusErrors');

            if (self::unauthorized($errors)) {
                $this->note('MyGLS refused the credentials while tracking.');

                return $out;
            }

            $list = self::rows($response->at('ParcelStatusList'));

            $out[] = $list === [] && $errors !== []
                ? new TrackingInfo(['trackingNumber' => $number, 'notFound' => true])
                : self::trackingInfo($number, $list);
        }

        return $out;
    }

    /**
     * Turn a `ParcelStatusList` into the canonical history.
     *
     * The list is sorted by `StatusDate` rather than trusted in array order (ordering is not
     * documented). Informational scans (COD data, email sent) inherit the status the parcel had at
     * that moment, so they never move it.
     */
    private static function trackingInfo(string $number, array $list): TrackingInfo
    {
        $rows = [];

        foreach ($list as $i => $row) {
            $rows[] = [$i, self::parseDate((string)($row['StatusDate'] ?? '')), $row];
        }

        usort($rows, static fn($a, $b) => [$a[1]?->getTimestamp() ?? 0, $a[0]] <=> [$b[1]?->getTimestamp() ?? 0, $b[0]]);

        $current = TrackingStatus::PRE_TRANSIT;
        $statusText = '';
        $deliveredAt = null;
        $events = [];

        foreach ($rows as [, $at, $row]) {
            $code = trim((string)($row['StatusCode'] ?? ''));
            $description = trim((string)($row['StatusDescription'] ?? ''));
            $info = trim((string)($row['StatusInfo'] ?? ''));

            if (!in_array($code, self::INFORMATIONAL, true)) {
                $current = self::mapStatus($code, self::statusMap(), TrackingStatus::IN_TRANSIT);
                $statusText = $description;

                if ($current === TrackingStatus::DELIVERED) {
                    $deliveredAt = $at;
                }
            }

            $events[] = new TrackingEvent([
                'occurredAt' => $at,
                'status' => $current,
                'code' => $code,
                'description' => trim($description . ($info !== '' && $info !== $description ? ' – ' . $info : '')),
                'location' => trim((string)($row['DepotCity'] ?? '')),
            ]);
        }

        if ($events === []) {
            // Known to MyGLS but not scanned yet: say nothing rather than guess.
            return new TrackingInfo(['trackingNumber' => $number, 'status' => TrackingStatus::UNKNOWN]);
        }

        return new TrackingInfo([
            'trackingNumber' => $number,
            'status' => $current,
            'statusText' => $statusText,
            'deliveredAt' => $current === TrackingStatus::DELIVERED ? $deliveredAt : null,
            'events' => array_reverse($events),
        ]);
    }

    /**
     * MyGLS Appendix G codes onto the normalised vocabulary. Anything unlisted reads as in transit.
     *
     * Judgement calls (UNVERIFIED): 8 "stored, consignee collects" and 54 "delivered to parcel
     * box" are ready-for-pickup; 59 "ParcelShop pickup" is the customer collecting, so delivered;
     * 9 and 24 are in transit. 42 (disposed) and 87/90/91 (pickup cancelled) are exceptions.
     */
    private static function statusMap(): array
    {
        if (self::$statusMap !== null) {
            return self::$statusMap;
        }

        $groups = [
            TrackingStatus::PRE_TRANSIT => [51, 83, 84],
            TrackingStatus::IN_TRANSIT => array_merge([1, 2, 3, 6, 7, 9, 10, 22, 24, 26, 27, 41, 46, 47, 53, 80, 85, 86], range(60, 67), range(70, 76)),
            TrackingStatus::OUT_FOR_DELIVERY => [4, 32],
            TrackingStatus::DELIVERED => [5, 58, 59, 92, 93],
            TrackingStatus::READY_FOR_PICKUP => [8, 54, 55, 56, 97],
            TrackingStatus::EXCEPTION => array_merge(range(11, 21), [25, 28, 29, 30, 31], range(33, 39), [42, 43, 44, 57, 68, 69, 87, 88, 89, 90, 91], range(401, 404), [420]),
            TrackingStatus::RETURNED => [23, 40],
        ];

        $map = [];

        foreach ($groups as $status => $codes) {
            foreach ($codes as $code) {
                $map[(string)$code] = $status;
            }
        }

        return self::$statusMap = $map;
    }

    // =========================================================================
    // Pickup points
    // =========================================================================

    /**
     * Every GLS parcel shop and locker in one country, in one page, from GLS's public map feed.
     */
    public function pickupPoints(PointQuery $query): PointPage
    {
        $cc = strtolower($query->countryCode);

        if (!isset(self::COUNTRIES[$cc])) {
            return PointPage::of([]);
        }

        $response = $this->transport()->get(sprintf(self::MAP_FEED, $cc));

        if (!$response->ok()) {
            return PointPage::failed(Craft::t('carrier', 'The GLS point map did not answer: {error}', ['error' => $response->errorMessage()]));
        }

        $points = [];

        foreach (self::rows($response->at('items')) as $item) {
            $point = self::point($item, strtoupper($cc));

            if ($point !== null && ($query->types === [] || in_array($point->type, $query->types, true))) {
                $points[] = $point;
            }
        }

        return PointPage::of($points);
    }

    private static function point(array $item, string $country): ?PickupPoint
    {
        $id = trim((string)($item['id'] ?? ''));

        if ($id === '') {
            return null;
        }

        $contact = is_array($item['contact'] ?? null) ? $item['contact'] : [];
        $location = is_array($item['location'] ?? null) ? array_values($item['location']) : [];
        $features = is_array($item['features'] ?? null) ? $item['features'] : [];

        return new PickupPoint([
            'id' => $id,
            'type' => ($item['type'] ?? '') === 'parcel-locker' ? PickupPoint::TYPE_LOCKER : PickupPoint::TYPE_SHOP,
            'name' => trim((string)($item['name'] ?? '')) ?: $id,
            'street' => trim((string)($contact['address'] ?? '')),
            'city' => trim((string)($contact['city'] ?? '')),
            'postcode' => trim((string)($contact['postalCode'] ?? '')),
            'countryCode' => strtoupper(trim((string)($contact['countryCode'] ?? ''))) ?: $country,
            'latitude' => is_numeric($location[0] ?? null) ? (float)$location[0] : null,
            'longitude' => is_numeric($location[1] ?? null) ? (float)$location[1] : null,
            'hours' => self::hours($item['hours'] ?? []),
            'codAllowed' => $features === [] || in_array('acceptsCash', $features, true) || in_array('acceptsCard', $features, true),
            'extra' => array_filter([
                'features' => $features,
                'glsType' => $item['type'] ?? null,
                'goldId' => $item['goldId'] ?? null,
                'externalId' => $item['externalId'] ?? null,
                'pickupTime' => $item['pickupTime'] ?? null,
                'phone' => $contact['phone'] ?? null,
                'wheelchairAccess' => $item['hasWheelchairAccess'] ?? null,
            ], static fn($v) => $v !== null && $v !== '' && $v !== []),
        ]);
    }

    /**
     * `[[1,"08:00","16:00"], …]` → "Mon 08:00–16:00; …". Rows with a second pair of times show it in
     * brackets; it is presumably a break, which is not confirmed.
     */
    private static function hours(mixed $rows): string
    {
        if (!is_array($rows) || $rows === []) {
            return '';
        }

        $days = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
        $parts = [];
        $allDay = 0;

        foreach ($rows as $row) {
            if (!is_array($row) || count($row) < 3 || !isset($days[(int)$row[0]])) {
                continue;
            }

            $row = array_values($row);

            if ($row[1] === '00:00' && $row[2] === '24:00') {
                $allDay++;
            }

            $part = $days[(int)$row[0]] . ' ' . $row[1] . '–' . $row[2];

            if (isset($row[3], $row[4])) {
                $part .= ' (' . $row[3] . '–' . $row[4] . ')';
            }

            $parts[] = $part;
        }

        return $allDay === 7 ? '24/7' : implode('; ', $parts);
    }

    // =========================================================================
    // Building requests
    // =========================================================================

    /**
     * POST one MyGLS method with the credentials merged in.
     *
     * Encoded here, with PHP's default flags, so the `/` in WCF dates leaves as `\/` whatever the
     * transport's own JSON handling does — see the class docblock.
     */
    private function call(string $method, array $payload, string $service = 'ParcelService'): Response
    {
        $auth = $this->auth();
        $body = array_merge($auth instanceof MyGlsAuth ? $auth->body() : [], $payload);

        return $this->transport()->request('POST', "/$service.svc/json/$method", [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => (string)json_encode($body),
        ]);
    }

    private function parcelPayload(ShipmentRequest $request, bool $psd, array &$warnings): array
    {
        $to = $request->shipTo;
        $parcel = [
            'ClientNumber' => (int)$this->setting('clientNumber'),
            'ClientReference' => $request->reference,
            'Count' => count($request->parcels),
            'Content' => self::content($request),
            'PickupAddress' => self::address($request->shipFrom),
            'DeliveryAddress' => self::address($to),
            'ParcelPropertyList' => [],
        ];

        if ($request->shipDate) {
            $parcel['PickupDate'] = self::wcfDate($request->shipDate);
        }

        foreach ($request->parcels as $piece) {
            $property = array_filter([
                'Content' => mb_substr($piece->description, 0, 40),
                'Weight' => $piece->weightG > 0 ? Units::gramsToKg($piece->weightG) : null,
            ], static fn($v) => $v !== null && $v !== '');

            if ($piece->hasDimensions()) {
                $property['Length'] = (int)Units::mmToCm($piece->lengthMm);
                $property['Width'] = (int)Units::mmToCm($piece->widthMm);
                $property['Height'] = (int)Units::mmToCm($piece->heightMm);
            }

            $parcel['ParcelPropertyList'][] = $property;
        }

        if ($request->hasCod()) {
            $parcel['CODAmount'] = round($request->codAmount, 2);
            $parcel['CODReference'] = $request->codReference !== '' ? $request->codReference : $request->reference;
            $parcel['CODCurrency'] = strtoupper($request->codCurrency !== '' ? $request->codCurrency : $request->currency);
        }

        $services = [];

        if ($psd) {
            $services[] = ['Code' => 'PSD', 'PSDParameter' => ['StringValue' => $request->pointId]];
        }

        if (in_array(Accessorial::SATURDAY_DELIVERY, $request->accessorials, true)) {
            $services[] = ['Code' => 'SAT'];
        }

        // FlexDelivery: email first, because the SMS variant is refused without it (error 30).
        if (in_array(Accessorial::NOTIFY, $request->accessorials, true)) {
            if ($to->email !== '') {
                $services[] = ['Code' => 'FDS', 'FDSParameter' => ['Value' => $to->email]];

                if ($to->phone !== '') {
                    $services[] = ['Code' => 'FSS', 'FSSParameter' => ['Value' => Addresses::normalisePhone($to->phone)]];
                }
            } else {
                $warnings[] = Craft::t('carrier', 'No FlexDelivery notice was requested: the recipient has no email address.');
            }
        }

        if ($services !== []) {
            $parcel['ServiceList'] = $services;
        }

        return $parcel;
    }

    private function printPayload(array $parcelIds, string $format): array
    {
        $payload = [
            'ParcelIdList' => array_map('intval', $parcelIds),
            'TypeOfPrinter' => $this->printerFor($format),
            'PrintPosition' => 1,
            'ShowPrintDialog' => false,
        ];

        if ($this->boolSetting('hidePhone')) {
            $payload['HidePhoneNumberOnLabels'] = true;
        }

        return $payload;
    }

    /**
     * MyGLS `Address`. `HouseNumber` takes digits only; whatever follows them, and the address's
     * second line, go in `HouseNumberInfo`.
     */
    private static function address(Address $address): array
    {
        $street = $address->street1;
        $number = $address->houseNumber;

        if ($number === '') {
            [$street, $number] = Addresses::splitHouseNumber($street);
        }

        $digits = '';
        $suffix = trim($number);

        if (preg_match('/^0*(\d+)\s*(.*)$/u', $suffix, $m) && $m[1] !== '0') {
            $digits = $m[1];
            $suffix = trim($m[2]);
        }

        $info = trim(implode(', ', array_filter([$suffix, trim($address->street2)])));

        return array_filter([
            'Name' => $address->company !== '' ? $address->company : $address->name,
            'Street' => $street,
            'HouseNumber' => $digits,
            'HouseNumberInfo' => $info,
            'City' => $address->city,
            'ZipCode' => $address->postcode,
            'CountryIsoCode' => strtoupper($address->countryCode),
            'ContactName' => $address->contactName(),
            // International format (+385…), which the SMS services require.
            'ContactPhone' => Addresses::normalisePhone($address->phone),
            'ContactEmail' => $address->email,
        ], static fn($v) => $v !== '');
    }

    /** What GLS prints as the contents. Mandatory in Serbia, and on some accounts elsewhere. */
    private static function content(ShipmentRequest $request): string
    {
        $descriptions = array_unique(array_filter(array_map(static fn($p) => trim($p->description), $request->parcels)));

        return mb_substr($descriptions !== [] ? implode(', ', $descriptions) : $request->reference, 0, 100);
    }

    private function printerFor(string $format): string
    {
        return match ($format) {
            LabelFormat::PDF_A6 => 'Thermo',
            LabelFormat::ZPL => in_array($this->setting('zplPrinter'), ['ThermoZPL', 'ThermoZPL_300DPI'], true) ? $this->setting('zplPrinter') : 'ThermoZPL',
            default => in_array($this->setting('a4Layout'), ['A4_2x2', 'A4_4x1'], true) ? $this->setting('a4Layout') : 'A4_2x2',
        };
    }

    private function country(): string
    {
        $country = strtolower((string)$this->setting('country', 'hr'));

        return isset(self::COUNTRIES[$country]) ? $country : 'hr';
    }

    private function language(): string
    {
        return self::LANGUAGES[$this->country()];
    }

    // =========================================================================
    // Reading answers
    // =========================================================================

    /** A method's own `ErrorInfo` list. */
    private static function errorList(Response $response, string $key): array
    {
        return self::rows($response->at($key));
    }

    private static function rows(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }

    private static function unauthorized(array $errors): bool
    {
        foreach ($errors as $error) {
            if ((int)($error['ErrorCode'] ?? 0) === -1 || stripos((string)($error['ErrorDescription'] ?? ''), 'unauthori') !== false) {
                return true;
            }
        }

        return false;
    }

    private static function hasCode(array $errors, array $codes): bool
    {
        foreach ($errors as $error) {
            if (in_array((int)($error['ErrorCode'] ?? 0), $codes, true)) {
                return true;
            }
        }

        return false;
    }

    private static function describe(array $errors): string
    {
        $parts = [];

        foreach ($errors as $error) {
            $description = trim((string)($error['ErrorDescription'] ?? ''));
            $code = $error['ErrorCode'] ?? null;
            $parts[] = trim($description . ($code !== null ? ' (GLS error ' . $code . ')' : ''));
        }

        return implode(' ', array_unique(array_filter($parts)));
    }

    private function failureMessage(array $errors): string
    {
        if (self::unauthorized($errors)) {
            return Craft::t('carrier', 'MyGLS refused the username or password. Check them, and that this connection points at the right country and environment.');
        }

        return Craft::t('carrier', 'GLS refused this: {detail}', ['detail' => self::describe($errors)]);
    }

    /**
     * A `byte[]` as MyGLS's JSON binding sends it — a list of ints — decoded in chunks so a 300,000
     * element array does not become 300,000 function arguments at once. A base64 string (the
     * SOAP binding's encoding) is accepted too.
     */
    private static function bytes(mixed $value): string
    {
        if (is_string($value) && $value !== '') {
            return (string)base64_decode($value, true);
        }

        if (!is_array($value) || $value === []) {
            return '';
        }

        $out = '';

        foreach (array_chunk($value, 8192) as $chunk) {
            $out .= pack('C*', ...array_map('intval', $chunk));
        }

        return $out;
    }

    /** The format the bytes really are, preferring the one asked for when they agree. */
    private static function sniff(string $bytes, string $requested): string
    {
        if (str_starts_with($bytes, '%PDF')) {
            return LabelFormat::isPdf($requested) ? $requested : LabelFormat::PDF_A6;
        }

        if (LabelFormat::bytesMatch(LabelFormat::ZPL, $bytes)) {
            return LabelFormat::ZPL;
        }

        return $requested;
    }

    private static function parcelIdsOf(ShipmentRef $shipment): array
    {
        $ids = self::uniqueStrings($shipment->data['parcelIds'] ?? []);

        if ($ids === [] && ctype_digit($shipment->shipmentId)) {
            $ids = [$shipment->shipmentId];
        }

        return array_values(array_filter($ids, 'ctype_digit'));
    }

    private static function uniqueStrings(mixed $values): array
    {
        if (!is_array($values)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(static fn($v) => self::numberString($v), $values), static fn($v) => $v !== '')));
    }

    /** A `long` as a string, without float notation creeping in. */
    private static function numberString(mixed $value): string
    {
        if (is_int($value)) {
            return (string)$value;
        }

        if (is_float($value)) {
            return number_format($value, 0, '', '');
        }

        return is_scalar($value) ? trim((string)$value) : '';
    }

    /**
     * Parcel numbers are `long` on the wire. A number that is not all digits (typed by hand) is
     * sent as given, and MyGLS will report it as unknown.
     */
    private static function wireNumber(string $number): int|string
    {
        return ctype_digit($number) && strlen($number) < 19 ? (int)$number : $number;
    }

    private static function wcfDate(\DateTimeInterface $date): string
    {
        return '/Date(' . ($date->getTimestamp() * 1000) . ')/';
    }
}
