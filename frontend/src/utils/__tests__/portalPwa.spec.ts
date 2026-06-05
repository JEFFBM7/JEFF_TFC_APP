import { describe, expect, it } from 'vitest'
import {
  getPortalDeviceName,
  isPortalRole,
  isPwaRole,
  manifestHrefForRole,
} from '../portalPwa'

describe('portalPwa utils', () => {
  it('identifies family portal roles', () => {
    expect(isPortalRole('parent')).toBe(true)
    expect(isPortalRole('eleve')).toBe(true)
    expect(isPortalRole('admin')).toBe(false)
    expect(isPortalRole(null)).toBe(false)
  })

  it('identifies all installable PWA roles', () => {
    expect(isPwaRole('parent')).toBe(true)
    expect(isPwaRole('eleve')).toBe(true)
    expect(isPwaRole('admin')).toBe(true)
    expect(isPwaRole('enseignant')).toBe(true)
    expect(isPwaRole('secretariat')).toBe(true)
    expect(isPwaRole(null)).toBe(false)
  })

  it('returns role-specific manifest hrefs', () => {
    expect(manifestHrefForRole('parent')).toBe('/pwa/manifest-parent.webmanifest')
    expect(manifestHrefForRole('eleve')).toBe('/pwa/manifest-student.webmanifest')
    expect(manifestHrefForRole('admin')).toBe('/pwa/manifest-admin.webmanifest')
    expect(manifestHrefForRole('enseignant')).toBe('/pwa/manifest-teacher.webmanifest')
    expect(manifestHrefForRole('secretariat')).toBe('/pwa/manifest-secretariat.webmanifest')
  })

  it('uses spa-web device name outside standalone mode', () => {
    expect(getPortalDeviceName()).toBe('spa-web')
  })
})
