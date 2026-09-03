<script setup lang="ts">
/**
 * The icon a menu entry asked for in its module manifest.
 *
 * Icons are imported one by one rather than with `import * as icons`: the
 * latter pulls all ~1500 Lucide icons into the bundle, and the storefront
 * budget (100 kB gzip) is not the admin's, but nobody wants a megabyte of
 * unused SVG either.
 *
 * The names here are the ones the shipped manifests actually use — a test
 * (NavIconCoverageTest) fails if a manifest asks for one that is missing,
 * so a new module cannot quietly end up with the fallback.
 */
import {
  Circle,
  Contact,
  CreditCard,
  Download,
  Eye,
  FileSpreadsheet,
  FileText,
  FolderTree,
  Gauge,
  Globe,
  Layout,
  LogOut,
  Package,
  Palette,
  Receipt,
  Rss,
  Search,
  Send,
  SlidersHorizontal,
  Star,
  Store,
  Tag,
  Truck,
  Upload,
  User,
  Users,
} from 'lucide-vue-next'
import { computed, type Component } from 'vue'

const props = withDefaults(
  defineProps<{
    name: string | null
    /** Tailwind size classes; the caller decides how big an icon is. */
    class?: string
  }>(),
  { class: 'h-5 w-5' },
)

const ICONS: Record<string, Component> = {
  // From the module manifests.
  package: Package,
  upload: Upload,
  'folder-tree': FolderTree,
  send: Send,
  receipt: Receipt,
  'file-text': FileText,
  users: Users,
  tag: Tag,
  'file-spreadsheet': FileSpreadsheet,
  rss: Rss,
  layout: Layout,
  truck: Truck,

  // Kernel entries, which have no manifest to declare them.
  gauge: Gauge,
  download: Download,
  globe: Globe,
  palette: Palette,
  sliders: SlidersHorizontal,
  user: User,
  'log-out': LogOut,
  star: Star,
  store: Store,
  contact: Contact,
  search: Search,
  eye: Eye,
  'credit-card': CreditCard,
}

/**
 * An unknown name draws a neutral mark rather than nothing at all.
 *
 * A missing icon must not collapse the row it sits in — a menu with one
 * entry visibly out of line is easy to spot and fix; a menu that throws is
 * an admin nobody can open.
 */
const icon = computed<Component>(() => ICONS[props.name ?? ''] ?? Circle)
</script>

<template>
  <component :is="icon" :class="props.class" aria-hidden="true" :stroke-width="1.75" />
</template>
