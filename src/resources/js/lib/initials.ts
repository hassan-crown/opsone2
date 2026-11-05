const ARABIC_SCRIPT = /\p{Script=Arabic}/u;

/** Arabic definite article ("ال") is skipped so "الحسن" gives "ح", not "ا". */
function getInitial(name: string): string {
    const stripped = name.length > 2 ? name.replace(/^ال/u, '') : name;

    return Array.from(stripped)[0] ?? '';
}

/**
 * Avatar initials for a person's name.
 *
 * Arabic names always give ONE letter: joining two Arabic initials can spell
 * a real (and awkward) word. Mixed names are decided by the first name's script; a Latin first name
 * followed by an Arabic last name also stays at one letter.
 * Latin names give first + last initials.
 */
export function initialsOf(fullName: string): string {
    const names = fullName.trim().split(/\s+/u).filter(Boolean);

    if (names.length === 0) {
        return '';
    }

    const last = names[names.length - 1];

    // A Latin first name with an Arabic last name would give a mixed-script pair: keep one letter too.
    if (
        names.length === 1 ||
        ARABIC_SCRIPT.test(names[0]) ||
        ARABIC_SCRIPT.test(last)
    ) {
        return getInitial(names[0]).toUpperCase();
    }

    return `${getInitial(names[0])}${getInitial(last)}`.toUpperCase();
}
