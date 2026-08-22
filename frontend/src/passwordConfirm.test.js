import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'

// Vitest runs from the project root.
const source = readFileSync('src/App.vue', 'utf8')

/**
 * Every place a password is set asks for it twice.
 *
 * A typo locks the account's owner out and nobody finds out until they try to
 * sign in, so the guard belongs on all of them — not just the one that happened
 * to be written first. These read the shell's source because App.vue loads the
 * whole application on mount and cannot be sensibly mounted in a unit test.
 */
describe('password confirmation', () => {
  const forms = [
    ['creating a user', 'userForm.password_confirmation'],
    ['creating a teacher', 'teacherForm.password_confirmation'],
    ['editing a teacher', 'editingTeacher.password_confirmation'],
    ['changing sign-in details', 'credentialsModal.password_confirmation'],
  ]

  it.each(forms)('%s has a confirmation field', (_label, binding) => {
    expect(source).toContain(`v-model="${binding}"`)
  })

  it('refuses to save when the two entries differ', () => {
    // One message and one rule, called from each save path.
    expect(source).toContain('const passwordMismatch = ')

    const guards = source.match(/notifyError\(passwordMismatch\(\)\)/g) ?? []
    expect(guards.length).toBeGreaterThanOrEqual(3)
  })

  it('keeps the confirmation out of what is sent to the server', () => {
    // The teacher edit spreads the rest of the form into the payload, so the
    // confirmation has to be pulled out by name.
    expect(source).toContain('password_confirmation: _confirmation')
  })

  it('no longer offers demo accounts or a filled-in password', () => {
    expect(source).not.toContain('demoAccounts')
    expect(source).not.toContain("password: 'password'")
    expect(source).toContain("const credentials = ref({ email: '', password: '' })")
  })
})
