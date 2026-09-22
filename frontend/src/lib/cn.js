/** Une clases CSS ignorando valores vacíos: cn('a', cond && 'b'). */
export function cn(...parts) {
  return parts.flat().filter(Boolean).join(' ')
}
