const currencyFormatter = new Intl.NumberFormat('en-PH', {
    style: 'currency',
    currency: 'PHP',
    minimumFractionDigits: 2,
});

export function formatCurrency(value) {
    return currencyFormatter.format(Number(value));
}
