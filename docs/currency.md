# Currency handling

All selling amounts use Omani rials (OMR), stored as integer baisa (1 OMR = 1,000 baisa). Supplier AED prices are stored as integer fils (1 AED = 100 fils). Convert each unit cost to baisa once, then calculate line totals from unit price × integer quantity.

The initial **indicative** rate is 0.104699 OMR per AED, rounded from `1 / (2.6008 × 3.6725)`. It is a costing starting point, not a live bank quotation; it excludes spreads, fees, shipping and duties. Change it in Settings or on a new quotation. The rate is saved with each quotation and cannot be changed by later currency settings or supplier synchronisation. Selling prices are independent of converted costs.

References checked 10 September 2026:
- [Central Bank of Oman: fixed peg](https://cbo.gov.om/Pages/FixedPeg.aspx): USD 2.6008 per OMR.
- [Central Bank of UAE: foreign exchange operations](https://centralbank.ae/en/our-operations/monetary-policy-and-domestic-markets/domestic-market-operations/): USD/AED intervention rates of 3.672 and 3.673; 3.6725 is their midpoint.

Supplier costs remain internal and are excluded from the printable customer quotation. No tax rate is assumed. The displayed total is a subtotal: tax, delivery and printing are not automatically calculated in this first local version.
