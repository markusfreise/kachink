// Project billing mode.
export default {
  en: {
    billing: {
      mode: 'Billing',
      all: 'All billing modes',
      modes: { none: 'Not billed', fixed: 'Fixed price', hourly: 'Time and material' },
      hints: {
        none: 'Internal work, entries are never billable.',
        fixed: 'Budget and the amount billed so far in EUR; hours are still tracked for the effort.',
        hourly: 'Budget in hours, billed by the hourly rate.',
      },
      fixedOpen: 'Fixed price (open)',
      fixedBilled: 'Fixed price (billed)',
      fixedPrice: 'Fixed price',
      budgetAmount: 'Fixed price',
      billedAmount: 'Billed so far',
      billedOf: '{billed} of {total} billed',
      billedRemaining: '{billed} billed, {remaining} open',
    },
  },
  de: {
    billing: {
      mode: 'Abrechnung',
      all: 'Alle Abrechnungsarten',
      modes: { none: 'Ohne Abrechnung', fixed: 'Fester Preis', hourly: 'Nach Aufwand' },
      hints: {
        none: 'Interne Arbeit, Einträge sind nie abrechenbar.',
        fixed: 'Budget und bislang abgerechneter Betrag in Euro; Stunden werden weiter für den Aufwand erfasst.',
        hourly: 'Budget in Stunden, Abrechnung über den Stundensatz.',
      },
      fixedOpen: 'Festpreis (offen)',
      fixedBilled: 'Festpreis (abgerechnet)',
      fixedPrice: 'Festpreis',
      budgetAmount: 'Festpreis',
      billedAmount: 'Bislang abgerechnet',
      billedOf: '{billed} von {total} abgerechnet',
      billedRemaining: '{billed} abgerechnet, {remaining} offen',
    },
  },
}
