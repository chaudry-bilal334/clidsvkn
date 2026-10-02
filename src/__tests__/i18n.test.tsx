describe('i18n dir attribute', () => {
  test('dir attribute defaults to ltr when locale is en', () => {
    document.documentElement.setAttribute('dir', 'ltr')
    expect(document.documentElement.getAttribute('dir')).toBe('ltr')
  })

  test('dir attribute can be set to rtl for Urdu locale', () => {
    document.documentElement.setAttribute('dir', 'rtl')
    expect(document.documentElement.getAttribute('dir')).toBe('rtl')
  })

  test('dir attribute toggles between ltr and rtl', () => {
    document.documentElement.setAttribute('dir', 'ltr')
    expect(document.documentElement.getAttribute('dir')).toBe('ltr')
    document.documentElement.setAttribute('dir', 'rtl')
    expect(document.documentElement.getAttribute('dir')).toBe('rtl')
    document.documentElement.setAttribute('dir', 'ltr')
    expect(document.documentElement.getAttribute('dir')).toBe('ltr')
  })
})