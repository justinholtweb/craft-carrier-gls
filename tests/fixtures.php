<?php
/**
 * Recorded happy-path answers for the Carrier conformance suite.
 *
 * Shapes follow the MyGLS API manual (ver. 25.12.11) and the official PHP sample: HTTP 200, a
 * per-method error list that is empty on success, labels as a JSON array of byte values, and WCF
 * dates as "/Date(ms+0200)/". The map feed follows a live sample of
 * map.gls-croatia.com/data/deliveryPoints/hr.json (2026-10-03), trimmed to three points.
 */

use justinholtweb\carrier\base\Response;
use justinholtweb\carrier\helpers\Pdf;

return [
    'gls' => static function(string $method, string $url, array $options): Response {
        $body = json_decode((string)($options['body'] ?? ''), true) ?: [];
        $path = (string)parse_url($url, PHP_URL_PATH);

        $label = static function(string $printer, array $lines): array {
            $data = str_starts_with($printer, 'ThermoZPL')
                ? "^XA\n" . implode("\n", array_map(static fn($l, $i) => sprintf('^FO40,%d^A0N,30,30^FD%s^FS', 40 + $i * 40, $l), $lines, array_keys($lines))) . "\n^XZ"
                : Pdf::text(array_merge(['GLS'], $lines), 595.3, 841.9);

            return array_values(unpack('C*', $data));
        };

        if (str_contains($url, 'map.gls-croatia.com/data/deliveryPoints/')) {
            $cc = strtoupper(basename($path, '.json'));

            return Response::json(200, ['items' => [
                [
                    'id' => $cc . '10000-PARCELLOC101', 'goldId' => 15814, 'name' => 'GLS Paketomat 1060',
                    'contact' => ['countryCode' => $cc, 'postalCode' => '10000', 'city' => 'Zagreb', 'address' => 'Samoborska cesta 28', 'name' => 'GLS Paketomat 1060'],
                    'location' => [45.8117, 15.8933],
                    'hours' => [[1, '00:00', '24:00'], [2, '00:00', '24:00'], [3, '00:00', '24:00'], [4, '00:00', '24:00'], [5, '00:00', '24:00'], [6, '00:00', '24:00'], [7, '00:00', '24:00']],
                    'features' => ['acceptsCard', 'pickup', 'delivery'],
                    'type' => 'parcel-locker', 'externalId' => '1060', 'parcelLockerExternalProvider' => 4, 'pickupTime' => '14:00', 'hasWheelchairAccess' => false,
                ],
                [
                    'id' => $cc . '10000-CSOMAGPONT', 'goldId' => 2351, 'name' => 'Tisak Ilica',
                    'contact' => ['countryCode' => $cc, 'postalCode' => '10000', 'city' => 'Zagreb', 'address' => 'Ilica 120', 'name' => 'Tisak', 'phone' => '+38514444444'],
                    'location' => [45.8131, 15.9601],
                    'hours' => [[1, '08:00', '16:00', '10:00', '14:00'], [2, '08:00', '16:00'], [3, '08:00', '16:00'], [4, '08:00', '16:00'], [5, '08:00', '16:00'], [6, '08:00', '13:00']],
                    'features' => ['acceptsCash', 'acceptsCard', 'pickup', 'delivery'],
                    'type' => 'parcel-shop', 'externalId' => null, 'pickupTime' => '15:00', 'hasWheelchairAccess' => true,
                ],
                [
                    'id' => $cc . '21000-PARCELLOC204', 'goldId' => 16021, 'name' => 'GLS Paketomat 2040',
                    'contact' => ['countryCode' => $cc, 'postalCode' => '21000', 'city' => 'Split', 'address' => 'Put Supavla 1'],
                    'location' => [43.5147, 16.4435],
                    'hours' => [],
                    'features' => ['pickup', 'delivery'],
                    'type' => 'parcel-locker',
                ],
            ]]);
        }

        switch (basename($path)) {
            case 'PrintLabels':
                $parcel = $body['ParcelList'][0] ?? [];
                $count = max(1, (int)($parcel['Count'] ?? 1));
                $infos = [];

                for ($i = 0; $i < $count; $i++) {
                    $infos[] = ['ClientReference' => $parcel['ClientReference'] ?? '', 'ParcelId' => 123456789 + $i, 'ParcelNumber' => 5012345678 + $i];
                }

                return Response::json(200, [
                    'Labels' => $label((string)($body['TypeOfPrinter'] ?? 'A4_2x2'), array_map(static fn($r) => (string)$r['ParcelNumber'], $infos)),
                    'PrintLabelsErrorList' => [],
                    'PrintLabelsInfoList' => $infos,
                ]);

            case 'GetPrintedLabels':
                $ids = $body['ParcelIdList'] ?? [];

                return Response::json(200, [
                    'Labels' => $label((string)($body['TypeOfPrinter'] ?? 'A4_2x2'), array_map('strval', $ids)),
                    'PrintDataInfoList' => array_map(static fn($id) => ['ParcelId' => $id, 'ParcelNumber' => 5012345678 + ($id - 123456789), 'Depot' => '10', 'Sort' => 'A1'], $ids),
                    'GetPrintedLabelsErrorList' => [],
                ]);

            case 'DeleteLabels':
                return Response::json(200, [
                    'SuccessfullyDeletedList' => array_map(static fn($id) => ['ParcelId' => $id, 'SubParcelIdList' => []], $body['ParcelIdList'] ?? []),
                    'DeleteLabelsErrorList' => [],
                ]);

            case 'GetParcelListStatuses':
                return Response::json(200, [
                    'ParcelList' => array_map(static fn($n) => [
                        'ParcelNumber' => $n,
                        'Weight' => 2.5,
                        'ClientNumber' => 1,
                        'ClientReference' => 'TCK-1001',
                        // Deliberately out of order: MyGLS's ordering is not documented.
                        'ParcelStatusList' => [
                            ['StatusCode' => '5', 'StatusDate' => '/Date(1791273600000+0200)/', 'StatusDescription' => 'Delivered', 'StatusInfo' => 'Ana Kovač', 'DepotCity' => 'Zagreb', 'DepotNumber' => '10'],
                            ['StatusCode' => '51', 'StatusDate' => '/Date(1791100800000+0200)/', 'StatusDescription' => 'Parcel data entered into GLS IT system', 'StatusInfo' => '', 'DepotCity' => '', 'DepotNumber' => ''],
                            ['StatusCode' => '1', 'StatusDate' => '/Date(1791129600000+0200)/', 'StatusDescription' => 'The parcel was handed over to GLS', 'StatusInfo' => '', 'DepotCity' => 'Zagreb', 'DepotNumber' => '10'],
                            ['StatusCode' => '52', 'StatusDate' => '/Date(1791130000000+0200)/', 'StatusDescription' => 'COD data entered', 'StatusInfo' => '', 'DepotCity' => 'Zagreb', 'DepotNumber' => '10'],
                            ['StatusCode' => '4', 'StatusDate' => '/Date(1791255600000+0200)/', 'StatusDescription' => 'Out for delivery', 'StatusInfo' => '', 'DepotCity' => 'Zagreb', 'DepotNumber' => '10'],
                        ],
                    ], $body['ParcelNumberList'] ?? []),
                    'GetParcelListStatusesErrors' => [],
                ]);

            case 'GetParcelStatuses':
                return Response::json(200, [
                    'ClientReference' => 'TCK-1001',
                    'DeliveryCountryCode' => 'HR',
                    'DeliveryZipCode' => '10000',
                    'ParcelNumber' => $body['ParcelNumber'] ?? 0,
                    'Weight' => 2.5,
                    'POD' => null,
                    'ParcelStatusList' => [
                        ['StatusCode' => '51', 'StatusDate' => '/Date(1791100800000+0200)/', 'StatusDescription' => 'Parcel data entered into GLS IT system', 'StatusInfo' => '', 'DepotCity' => '', 'DepotNumber' => ''],
                    ],
                    'GetParcelStatusErrors' => [],
                ]);

            case 'GetParcelList':
                return Response::json(200, ['PrintDataInfoList' => [], 'GetParcelListErrors' => []]);
        }

        return Response::json(404, ['Message' => 'Endpoint not found.']);
    },
];
