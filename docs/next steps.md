# next steps

# 1. Graphs

 add dates at the bottom x-axis: 
- days have (hh)
- weeks (ddd)
- Months (dd)

In the legend make lines instead of squares 

In weekly and monthly graphs enhance the average price line with an min and a max area in form of some kind of shadow.

Advise me how token costly it is to let you change the buildup of the graphs in an animated way: the structure appears as blend in of 0.3 seconds, the graphs are written from left to right in 0.6 seconds.

# 2. UI
put graph and pseudo invoice in tabs

# Formula for gross electricity price in Vienna, Wiener Netze

$base_kWh = $epex + $provider_surcharge + $electricity_tax + $renewable_tax;

$annual_per_kWh = ($meter_fee + $renewable_fee) / 3000.0;

$net_before_utility_tax = $base_kWh + $annual_per_kWh;

$utility_tax = $net_before_utility_tax * $consumption_tax_rate;

$vat_base = $net_before_utility_tax + $utility_tax;

$gross_per_kWh = $vat_base * (1 + $vat_rate);


$gross_per_kWh =
    (
        $epex
        + $provider_surcharge
        + $electricity_tax
        + $renewable_tax
        + (($meter_fee + $renewable_fee) / yearly_consumption_estimation)
    ) * (1 + $vat_rate)
    + (
        $epex
        + $provider_surcharge
        + $electricity_tax
        + $renewable_tax
        + (($meter_fee + $renewable_fee) / yearly_consumption_estimation)
    ) * $consumption_tax_rate;



| variable 	| meaning 	| period 	| 
|------------	| ------------------ 	| --------------------------------------------	|
| renewable_fee  	| Erneuerbaren Förderpauschale 	|  per year 	| 
| meter_fee_ 	|  Zählergebühr 	|  per year 	| 
| ---------------------	| -----------------------------------	| --------------------------------	| 
| epex 	|  EPEX Preis 	|  per kWh 	| 
| provider_surcharge	|  Aufschlag von Hofer 	|  per kwh |	| 
| electricity_tax 	|  Elektrizitätsabgabe 	| per kWh	| 
| renewable_tax 	|  Erneuerbaren Förderbeitrag 	| per kWh	| 
| consumptions_tax	|  Gebrauchsabgabe Wien 	|  per net kWh	| 
| vat 	|  Umsatzsteuer 	| on everything but the consumption tax	| 
|	| 	| 	| 

## Ab 1.1.2022

provider_surcharge = 1,9 ct/kwh
electricity_tax = 0 ct/kwh
renewable_tax = 0 ct/kwh
renewable_fee = 0 €/Jahr
meter_fee_ = 4,695 €/Jahr
consumptions_tax = 6% 
vat = 10%

## ab 1.1.2025

provider_surcharge = 1,9 ct/kwh
electricity_tax = 0 ct/kwh
renewable_tax = 0,796 ct/kwh
renewable_fee = 19,02 €/Jahr
meter_fee_ = 4,695 €/Jahr
consumptions_tax = 6% 
vat = 10%

## ab 1.1.2026

provider_surcharge = 1,9 ct/kwh
electricity_tax = 0,1 ct/kwh
ern_beitrag = 0,796 ct/kwh
renewable_fee = 19,02 €/Jahr
meter_fee_ = 4,695 €/Jahr
consumptions_tax = 6% 
vat = 10%

## ab 1.3.2026

provider_surcharge = 1,9 ct/kwh
electricity_tax = 0,1 ct/kwh
renewable_tax = 0,796 ct/kwh
renewable_fee = 19,02 €/Jahr
meter_fee_ = 4,695 €/Jahr
consumptions_tax = 7% 
vat = 10%
