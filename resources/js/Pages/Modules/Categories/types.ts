export type CategoryNode = {
  id: number
  parent_id: number | null
  name: string
  slug: string
  depth: number
  is_visible: boolean
  url: string
  children: CategoryNode[]
}
