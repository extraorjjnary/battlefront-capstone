const unitFormatter = new Intl.NumberFormat('en-PH', {
    maximumFractionDigits: 0,
});

export function formatForecastUnits(value) {
    return unitFormatter.format(Math.round(Number(value)));
}
