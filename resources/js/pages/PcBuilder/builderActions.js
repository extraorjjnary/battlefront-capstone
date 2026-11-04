import componentDefinitions from '../../../data/pc-builder-components.json' with { type: 'json' };

export { componentDefinitions };

export function allowsMultipleComponents(type) {
    return componentDefinitions[type]?.multiple ?? false;
}

export function cartBlocker({ checking, error, validation }) {
    if (checking)
        return 'Checking compatibility. Please wait before adding this build.';
    if (error)
        return 'Resolve the error above, then check compatibility again.';
    if (!validation) return 'Check compatibility before adding this build.';
    if (!validation.can_purchase)
        return (
            validation.purchase_blockers?.join(' ') ||
            'Resolve confirmed compatibility conflicts before adding these parts.'
        );
    return '';
}
