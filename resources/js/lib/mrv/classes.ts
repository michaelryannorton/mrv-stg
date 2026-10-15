/**
 * Joins class names, leaving out the false ones: classes('btn-toggle', active && 'active'). Used instead of a template
 * literal with conditional pieces in className: prettier-plugin-tailwindcss strips the spaces in front of the pieces
 * (`btn-toggle${x ? ' active' : ''}` becomes `btn-toggle${x ? 'active' : ''}`, one word) when it formats the file.
 */
export const classes = (...parts: Array<string | false | null | undefined>): string => parts.filter(Boolean).join(' ');
