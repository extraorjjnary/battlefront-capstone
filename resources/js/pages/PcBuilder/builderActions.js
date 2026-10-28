export function allowsMultipleComponents(type) {
    return ['memory', 'storage', 'fan', 'network_adapter'].includes(type);
}

export function cartBlocker({ checking, error, missing, validation }) {
    if (checking)
        return 'Checking compatibility. Please wait before adding this build.';
    if (error)
        return 'Resolve the error above, then check compatibility again.';
    if (missing.length)
        return `Choose the remaining components: ${missing.join(', ')}.`;
    if (!validation) return 'Check compatibility before adding this build.';
    if (!validation.complete)
        return 'Resolve the compatibility findings above before adding this build.';
    return '';
}
