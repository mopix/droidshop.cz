import { computed, onMounted, onUnmounted, ref, watch } from 'vue'

/**
 * State of the admin side navigation: which sections are open, whether the
 * menu is collapsed to icons, and whether the mobile drawer is showing.
 *
 * All of it survives navigation through localStorage — Inertia replaces the
 * page component on every visit, so anything held only in a ref would reset
 * and the user would re-open the same section after every click.
 *
 * localStorage is treated as optional throughout. In a private window, or
 * with site data blocked, reads and writes throw; an admin that will not open
 * because of a browser setting is worse than one that forgets which menu
 * sections were expanded.
 */

const OPEN_GROUPS_KEY = 'droidshop.admin.nav.open'
const COLLAPSED_KEY = 'droidshop.admin.nav.collapsed'

function readStorage(key: string): string | null {
  try {
    return window.localStorage.getItem(key)
  } catch {
    return null
  }
}

function writeStorage(key: string, value: string): void {
  try {
    window.localStorage.setItem(key, value)
  } catch {
    // Nothing to do and nothing worth telling the user: the menu works, it
    // just will not remember.
  }
}

/** Tailwind's `lg`, where the menu stops being a drawer and becomes a column. */
const DESKTOP = '(min-width: 1024px)'

export function useSideNav(activeGroups: () => string[]) {
  const openGroups = ref<string[]>([])
  const collapsedPreference = ref(false)
  const isDesktop = ref(true)
  const drawerOpen = ref(false)

  /**
   * Collapsing to a rail is a desktop idea.
   *
   * Below lg the menu is an overlay, where a rail would take the screen and
   * show nothing but icons. Someone who collapsed it on a laptop and then
   * opened the same admin on a phone must not find a menu they cannot read.
   */
  const collapsed = computed(() => collapsedPreference.value && isDesktop.value)

  const restore = (): void => {
    const stored = readStorage(OPEN_GROUPS_KEY)

    if (stored) {
      try {
        const parsed = JSON.parse(stored)
        if (Array.isArray(parsed)) {
          openGroups.value = parsed.filter((v) => typeof v === 'string')
        }
      } catch {
        // A corrupted value just means starting from collapsed.
      }
    }

    collapsedPreference.value = readStorage(COLLAPSED_KEY) === '1'
  }

  /**
   * The section containing the current page is always open, whatever was
   * stored — arriving on a page whose menu entry is hidden is disorienting,
   * and it is the one section the user demonstrably wants.
   */
  const syncActive = (): void => {
    for (const key of activeGroups()) {
      if (!openGroups.value.includes(key)) {
        openGroups.value = [...openGroups.value, key]
      }
    }
  }

  const isOpen = (key: string): boolean => openGroups.value.includes(key)

  const toggleGroup = (key: string): void => {
    openGroups.value = isOpen(key)
      ? openGroups.value.filter((k) => k !== key)
      : [...openGroups.value, key]

    writeStorage(OPEN_GROUPS_KEY, JSON.stringify(openGroups.value))
  }

  const toggleCollapsed = (): void => {
    collapsedPreference.value = !collapsedPreference.value
    writeStorage(COLLAPSED_KEY, collapsedPreference.value ? '1' : '0')
  }

  const openDrawer = (): void => {
    drawerOpen.value = true
  }

  const closeDrawer = (): void => {
    drawerOpen.value = false
  }

  // Escape closes the mobile drawer. Registered once for the whole layout
  // rather than on the drawer element, so it works no matter where focus sits
  // inside it.
  const onKeydown = (event: KeyboardEvent): void => {
    if (event.key === 'Escape' && drawerOpen.value) {
      closeDrawer()
    }
  }

  let media: MediaQueryList | null = null
  const onMediaChange = (event: MediaQueryListEvent | MediaQueryList): void => {
    isDesktop.value = event.matches

    // Resizing up past lg leaves the drawer conceptually open with nothing to
    // close it, since its close button and the overlay are both lg:hidden.
    if (event.matches) {
      drawerOpen.value = false
    }
  }

  onMounted(() => {
    restore()
    syncActive()
    window.addEventListener('keydown', onKeydown)

    media = window.matchMedia(DESKTOP)
    onMediaChange(media)
    media.addEventListener('change', onMediaChange)
  })

  onUnmounted(() => {
    window.removeEventListener('keydown', onKeydown)
    media?.removeEventListener('change', onMediaChange)
  })

  // The drawer is an overlay: leaving the page scrollable behind it means a
  // touch drag scrolls the wrong thing.
  watch(drawerOpen, (open) => {
    document.body.classList.toggle('overflow-hidden', open)
  })

  return {
    openGroups: computed(() => openGroups.value),
    collapsed,
    drawerOpen: computed(() => drawerOpen.value),
    isOpen,
    toggleGroup,
    toggleCollapsed,
    openDrawer,
    closeDrawer,
    syncActive,
  }
}
