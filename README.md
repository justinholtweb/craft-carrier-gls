# Carrier for GLS

GLS through the **MyGLS API** for [Carrier](https://justinholt.com/plugins/craft-carrier), the shipping-carrier gateway for Craft Commerce 5. It covers the seven MyGLS countries: Croatia, Hungary, Czechia, Romania, Slovenia, Slovakia and Serbia.

This package translates the MyGLS API and nothing else. Packing, checkout, label storage, bulk printing, tracking schedules, the pickup-point picker and the log all live in Carrier.

## What it does

| | |
|---|---|
| Labels | `PrintLabels` creates the parcel and returns its label in one call. Multi-parcel shipments go out as one parcel with `Count` = pieces |
| Label formats | PDF A4 (four per sheet, 2 × 2 or stacked), PDF thermal (MyGLS `Thermo`), ZPL at 203 or 300 dpi |
| Reprint and bulk print | `GetPrintedLabels`, up to 99 parcels in one document, which gives Carrier's bulk print its A4 sheets of four |
| Void | `DeleteLabels`. GLS only deletes parcels it has not scanned yet |
| Cash on delivery | `CODAmount`, `CODReference` (the order's COD reference, or its reference) and `CODCurrency` |
| Parcel shops and lockers | The `PSD` service, with the point chosen at checkout |
| Pickup points | Every GLS shop and locker in the country, synced daily |
| Tracking | Full scan history, 100 parcels per request (`GetParcelListStatuses`) |
| Extras | Saturday delivery (`SAT`). The "Notify" extra sends FlexDelivery by email (`FDS`) and, if there is a phone number, by SMS (`FSS`) |

MyGLS has no rates, collection or manifest methods. Price GLS methods with table rates; the daily collection in your contract picks parcels up.

## Setting up

1. Install Carrier, then this package: `composer require justinholtweb/craft-carrier-gls` and `php craft plugin/install carrier-gls`.
2. In **Carrier → Connections**, add a GLS connection:
   - **MyGLS country**: the country your GLS contract is with. Each country runs its own MyGLS, with separate logins.
   - **Environment**: production, or the test system (`api.test.mygls.…`), which needs **separate test credentials** from your local GLS office.
   - **MyGLS username and password**: the ones you use to sign in to MyGLS. Store the password in an environment variable.
   - **Client number**: from your GLS contract.
3. Press **Test connection**. A wrong password shows as "MyGLS refused the username or password", even though MyGLS answers it with HTTP 200.
4. For parcel-shop delivery, add a shipping method on the **GLS ParcelShop / ParcelLocker** service, tick "requires a pickup point", and sync points.

Recipients of parcel-shop and locker shipments need a name, a phone number and an email address. GLS refuses PSD parcels without all three, so Carrier checks before sending. Make sure your checkout collects a phone number. Commerce addresses have no phone field, so map one in Carrier's settings.

GLS labels are big: one A4 page arrives as roughly 300,000 integers of JSON. If label purchases fail with memory errors, raise PHP's `memory_limit` for queue workers.

## Pickup points

Points come from `https://map.gls-croatia.com/data/deliveryPoints/{country}.json`, the feed GLS's own checkout map widget reads. It needs no credentials and serves all seven countries (about 1,280 points for Croatia: roughly 1,080 lockers and 190 shops). Its `id` is what Carrier sends as the PSD point id.

That feed is **public but unofficial and undocumented**, so GLS could change it without notice. The official source is MyGLS's `GetDeliveryPoints` (added March 2026). This package does not use it yet: it could not be verified (the production endpoint timed out and the test endpoint failed), and neither the encoding of its data nor which of its ids MyGLS expects for PSD could be confirmed. If the map feed ever breaks, imported points keep working, and Carrier keeps the last good sync rather than emptying the picker.

## Unverified

This package was written against GLS's published documentation and official sample code, without a live MyGLS account. These points are marked **UNVERIFIED** in the code and should be checked on a first live shipment:

- **The PSD point id.** This package sends the map feed's `id` (for example `HR10000-PARCELLOC101`). It matches the manual's own example and is what GLS's widget returns, but it was not tested against a live parcel.
- Whether COD also needs a `COD` service code. Like GLS's sample code, this package sends only `CODAmount`.
- Whether the parcels of a multi-parcel shipment share one MyGLS `ParcelId`. Every id returned is stored and used to reprint and delete.
- Parcel dimensions are sent in whole centimetres.
- The exact page size of MyGLS's `Thermo` PDF (treated as A6). Whether ZPL arrives as text in the same byte array is not confirmed either; labels are checked byte by byte, and the format recorded is what actually came back.
- Whether a parcel can still be reprinted after GLS has scanned it. Carrier keeps the label from creation regardless.
- How a few scan codes should be classified: 8, 9, 24, 54 and 59.
- What the second pair of times in some opening-hours rows means. It is shown in brackets.
- Whether GLS refuses COD to a point that takes neither cash nor card. Such points are marked as not accepting COD.
- The format of GLS's public tracking page link.

## Requirements

Craft CMS 5.3+, Craft Commerce 5, Carrier 5, PHP 8.2+.

## Licence

The Craft License. See [LICENSE.md](LICENSE.md). Carrier for GLS is free: no editions, no licence key, and no licensing code in the plugin. It needs [Carrier](https://justinholt.com/plugins/craft-carrier), which is commercial.
