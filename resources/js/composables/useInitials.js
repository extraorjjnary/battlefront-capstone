function getInitial(name) {
    return Array.from(name)[0] ?? '';
}
export function getInitials(fullName) {
    if (!fullName) {
        return '';
    }
    const names = fullName.trim().split(/\s+/u).filter(Boolean);
    if (names.length === 0) {
        return '';
    }
    if (names.length === 1) {
        return getInitial(names[0]).toUpperCase();
    }
    return `${getInitial(names[0])}${getInitial(names[names.length - 1])}`.toUpperCase();
}
export function useInitials() {
    return { getInitials };
}
