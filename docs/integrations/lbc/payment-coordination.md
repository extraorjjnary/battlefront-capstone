# LBC shipping, collection, and Battlefront payment coordination

LBC transport charges, Battlefront customer payment, LBC COD/COP buyer collection, and LBC remittance to Battlefront are separate financial events. Public LBC pages describe sender-paid shipping charges and, for COD/COP, collection from the buyer followed by seller remittance. Those pages do not establish a Battlefront merchant API or settlement contract; see [official source evidence](api-sources.md).

## Payment streams to model separately

| Stream | Meaning | Evidence needed before integration |
| --- | --- | --- |
| Customer pays Battlefront | Battlefront order payment through its current methods and approved gateway/manual workflows. | Existing payment evidence and order policy remain authoritative. |
| Battlefront pays LBC shipping/valuation | Sender settles carrier charge through the selected LBC product's flow. | Merchant rate, fee/valuation, payer, timing, payment method, invoice/reference, and reconciliation contract. Do not assume the current saved customer delivery fee equals the final LBC charge. |
| Buyer pays LBC COD/COP | LBC collects an amount from the buyer at home delivery (COD) or branch pickup (COP). | Separate approval of service, amount source, collection evidence, failed/partial collection handling, and integration availability. |
| LBC remits collected funds | LBC pays proceeds to Battlefront by the contracted settlement method. | Merchant agreement, payout schedule/fees, settlement reference/report/API, reconciliation and dispute process. Delivery alone is not settlement proof. |

## Battlefront boundaries

Battlefront COD currently remains pending through delivery and requires the existing explicit administrator collection confirmation before payment verification/completion/sale. Do not reinterpret that action as LBC COD settlement. If LBC collects the buyer's money, design a distinct carrier-collection and settlement workflow with durable evidence and reconciliation before changing payment/order/sales behavior. LBC COP is likewise distinct from Battlefront pickup, which has no Shipment.

PayMongo attempts, authenticated payment events, reconciliation, closure, and refunds remain governed by the existing PayMongo contracts. LBC booking, tracking, cancellation, or remittance must not create PayMongo evidence or close a PayMongo flow. Preserve latest-state reconciliation and confirmed applicable-flow closure before eligible PayMongo-origin order cancellation or stock restoration. A carrier cancellation is not PayMongo closure or a refund.

Do not change order totals or delivery-fee snapshots after placement based on a later LBC quote/charge. Decide the checkout rate source, quote validity, variance ownership, and customer disclosure before any checkout/rate integration; that is separate scope. The default to absorb PayMongo fees remains governed by the approved PayMongo setup.

Until the product, charge flow, and collection/remittance evidence are verified and approved, preparation must not recommend that LBC COD/COP be used for Battlefront orders. See [the decision register](open-questions.md).
