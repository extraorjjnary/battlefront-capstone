export function nextComponentKey(components, type) {
    const used = new Set(components.map((component) => component.key));
    let index = 1;
    while (used.has(`${type}-${index}`)) index += 1;
    return `${type}-${index}`;
}

export function templatePayload(components) {
    return components.map(({ key, product_id, quantity, configuration }) => ({
        key,
        product_id,
        quantity: Number(quantity),
        configuration: JSON.parse(JSON.stringify({ ...configuration })),
    }));
}
