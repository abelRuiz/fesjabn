<script setup>
import { Head, Link, router, useForm, useRemember, usePage } from '@inertiajs/vue3'
import { computed, ref, watch, onMounted, nextTick } from 'vue'
import Toast from 'primevue/toast'
import { useToast } from 'primevue/usetoast'

const props = defineProps({
  inscritos: { type: Object, required: true },
  query: { type: [String, null], default: null },
  iglesia: { type: [String, null], default: null },
  iglesias: { type: Array, default: () => [] }, // lista para el select
  status: { type: [String, null], default: ''},
  distritos: { type: Array, default: () => [] }, // lista para el select
})

const page = usePage()
const toast = useToast()

function showErrors(errs) {
  if (!errs) return
  const list = Array.isArray(errs) ? errs : Object.values(errs).flat()
  if (list.length) {
    // asegurar que el Toast ya está montado
    nextTick(() => {
      toast.add({
        severity: 'error',
        summary: 'Validación',
        detail: list.join('\n'),
        life: 3500,
      })
    })
  }
}

// 1) Muestra errores si llegan después (e.g. 422 de Inertia)
watch(
  () => page.props.errors,
  (errs) => showErrors(errs),
  { flush: 'post' } // <- dispara tras el render
)

// 2) Si venías de un 422, muéstralos al montar (por si 'immediate' corría muy pronto)
onMounted(() => {
  if (page.props?.errors && Object.keys(page.props.errors).length) {
    // pequeño delay asegura que el <Toast> ya está suscrito
    setTimeout(() => showErrors(page.props.errors), 0)
  }
})
// --- estado ---
const search = ref(props.query ?? '')
const iglesiaFilter = ref(props.iglesia ?? '') // ← filtro iglesia
const selectedIds = useRemember([], 'inscritos:selected')
const statusFilter = ref(props.status ?? '')
const distritoFilter = ref(props.distrito ?? '')
// cache + helpers (igual que antes)
const selectedCache = ref({})
watch(() => props.inscritos.data, (rows) => {
  rows.forEach(row => { if (selectedIds.value.includes(row.id)) selectedCache.value[row.id] = row })
}, { immediate: true })

const pageIds = computed(() => props.inscritos.data.map(i => i.id))
const allOnPageSelected = computed(() => pageIds.value.length > 0 && pageIds.value.every(id => selectedIds.value.includes(id)))

function toggleAllOnPage(e) {
  const checked = e.target.checked
  if (checked) {
    const merged = new Set([...selectedIds.value, ...pageIds.value])
    props.inscritos.data.forEach(r => { selectedCache.value[r.id] = r })
    selectedIds.value = Array.from(merged)
  } else {
    selectedIds.value = selectedIds.value.filter(id => !pageIds.value.includes(id))
    pageIds.value.forEach(id => { delete selectedCache.value[id] })
  }
}
function toggleOne(row, e) {
  const checked = e.target.checked
  if (checked) { if (!selectedIds.value.includes(row.id)) selectedIds.value.push(row.id); selectedCache.value[row.id] = row }
  else { selectedIds.value = selectedIds.value.filter(x => x !== row.id); delete selectedCache.value[row.id] }
}

const pageRowsSelectedFirst = computed(() => {
  const sel = [], oth = []
  for (const r of props.inscritos.data) (selectedIds.value.includes(r.id) ? sel : oth).push(r)
  return [...sel, ...oth]
})

// --- navegación con debounce / filtro ---
// función helper para navegar manteniendo selección
function applyFilters(replace = true) {
  router.get(
    route('inscritos.index'),
    {
      query: search.value || undefined,
      iglesia: iglesiaFilter.value || undefined,
      page: undefined, // reset de paginación al cambiar filtro/texto
      status: statusFilter.value || undefined,
      distrito: distritoFilter.value || undefined,
    },
    { preserveState: true, preserveScroll: true, replace }
  )
}


let t = null
watch(search, () => { clearTimeout(t); t = setTimeout(() => applyFilters(true), 350) })
watch(iglesiaFilter, () => applyFilters(false)) // cambio inmediato al seleccionar iglesia
watch(statusFilter, () => applyFilters(false)) // cambio inmediato al seleccionar iglesia
watch(distritoFilter, () => applyFilters(false)) // cambio inmediato al seleccionar distrito

// acciones (igual)
const form = useForm({ action: '', ids: [] })
function submitAction(action) {
  if (selectedIds.value.length === 0) {
    toast.add({ severity: 'warn', summary: 'Atención', detail: 'Selecciona al menos un inscrito.', life: 2000 })
    return
  }
  form.action = action
  form.ids = selectedIds.value
  form.put('/actualizar-inscrito', {
    preserveScroll: true,
    preserveState: false,
    onSuccess: () => { selectedIds.value = []; selectedCache.value = {} },
    onFinish: () => { form.reset() },
  })
}

const selectedPanelRows = computed(() => selectedIds.value.map(id => selectedCache.value[id]).filter(Boolean))

function formatDate(dateString) {
  if (!dateString) return '-'
  const d = new Date(dateString)
  if (isNaN(d.getTime())) return dateString

  const dia = String(d.getDate()).padStart(2, '0')
  const mes = String(d.getMonth() + 1).padStart(2, '0')
  const ano = d.getFullYear()
  const hora = String(d.getHours()).padStart(2, '0')
  const min = String(d.getMinutes()).padStart(2, '0')
  
  return `${dia}-${mes}-${ano} ${hora}:${min}`
}
</script>

<template>
  <Head title="Inscritos" />
  <Toast appendTo="body" position="top-right" />

  <!-- Navbar FESJA -->
  <nav class="top-0 z-50 bg-white dark:bg-gray-800 shadow border-b border-gray-200 dark:border-gray-700">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="flex justify-between h-16">
        <div class="flex">
          <div class="shrink-0 flex items-center">
            <Link :href="route('inscritos.index')" class="text-xl font-bold text-indigo-600 dark:text-indigo-400 hover:text-indigo-500">
              FESJA BC 2026
            </Link>
          </div>
        </div>
        <div class="flex items-center">
          <span v-if="$page.props.auth?.user" class="text-sm text-gray-700 dark:text-gray-300 mr-4 font-medium">
            {{ $page.props.auth.user.name }}
          </span>
          <Link
            v-if="$page.props.auth?.user"
            :href="route('logout')"
            method="post"
              as="button"
            class="text-sm font-medium text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300"
          >
            Cerrar sesión
          </Link>
        </div>
      </div>
    </div>
  </nav>
    
  

  <div class="min-h-screen bg-gray-100 dark:bg-gray-900 text-gray-900 dark:text-gray-100 p-6">
    <!-- Toolbar -->
    <div class="max-w-7xl mx-auto mb-4 grid gap-3 sm:grid-cols-2 md:grid-cols-4 items-center">
      <!-- buscador -->
      <input
        type="text"
        v-model="search"
        placeholder="Buscar por ID, nombre, iglesia o distrito…"
        class="w-full px-3 py-2 rounded-md border border-gray-300 dark:border-gray-700 bg-white/90 dark:bg-gray-800"
      />

      <select
        v-model="distritoFilter"
        class="w-full px-3 py-2 rounded-md border border-gray-300 dark:border-gray-700 bg-white/90 dark:bg-gray-800"
        title="Filtrar por distrito"
      >
        <option value="">Todos los distritos</option>
        <option v-for="d in distritos" :key="d" :value="d">{{ d }}</option>
      </select>

      <!-- select iglesia -->
      <select
        v-model="iglesiaFilter"
        class="w-full px-3 py-2 rounded-md border border-gray-300 dark:border-gray-700 bg-white/90 dark:bg-gray-800"
        title="Filtrar por iglesia"
      >
        <option value="">Todas las iglesias</option>
        <option v-for="ig in iglesias" :key="ig" :value="ig">{{ ig }}</option>
      </select>

    <select
        v-model="statusFilter"
        class="w-full px-3 py-2 rounded-md border border-gray-300 dark:border-gray-700 bg-white/90 dark:bg-gray-800"
        title="Filtrar por iglesia"
      >
        <option value="">Todos</option>
        <option value="entrada">Con entrada</option>
        <option value="salida">Sin entrada</option>
      </select>

      <!-- botones -->
      <div class="sm:col-span-2 md:col-span-1 sm:justify-self-end flex gap-2">
        <button
          type="button"
          class="px-3 py-2 rounded-md border bg-emerald-600 border-emerald-600 text-white
                 hover:bg-emerald-700 hover:border-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-500/50
                 disabled:opacity-50"
          :disabled="selectedIds.length === 0"
          @click="submitAction('entrada')"
        >
          Marcar entrada
        </button>
        <button
          type="button"
          class="px-3 py-2 rounded-md border bg-rose-600 border-rose-600 text-white
                 hover:bg-rose-700 hover:border-rose-700 focus:outline-none focus:ring-2 focus:ring-rose-500/50
                 disabled:opacity-50"
          :disabled="selectedIds.length === 0"
          @click="submitAction('salida')"
        >
          Marcar salida
        </button>
      </div>
    </div>

    <!-- Panel seleccionados -->
    <div v-if="selectedPanelRows.length" class="max-w-7xl mx-auto mb-4 rounded-lg border border-amber-300/60 bg-amber-50 dark:bg-amber-900/20 dark:border-amber-500/50 p-3">
      <div class="flex items-center gap-2 mb-2">
        <span class="font-semibold">Seleccionados ({{ selectedIds.length }})</span>
        <span class="text-xs text-amber-700 dark:text-amber-200">Siempre visibles aunque el filtro no los muestre</span>
      </div>
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-amber-100/60 dark:bg-amber-800/40">
            <tr>
              <th class="px-3 py-2 text-left w-10"></th>
              <th class="px-3 py-2 text-left">id</th>
              <th class="px-3 py-2 text-left">Nombre</th>
              <th class="px-3 py-2 text-left">Distrito/Iglesia</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in selectedPanelRows" :key="'panel-'+row.id" class="odd:bg-white even:bg-gray-50 dark:odd:bg-white/5 dark:even:bg-transparent">
              <td class="px-3 py-2">
                <input type="checkbox" checked @change="toggleOne(row, $event)" />
              </td>
              <td class="px-3 py-2">{{ row.id }}</td>
              <td class="px-3 py-2">{{ row.nombre }}</td>
              <td class="px-3 py-2">
                {{ row.distrito }}<br>
                <small class="text-gray-500 dark:text-gray-400">{{ row.iglesia }}</small>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Tabla principal -->
    <div class="max-w-7xl mx-auto bg-white dark:bg-gray-800 rounded-lg shadow overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full table-auto">
          <thead class="bg-gray-50 dark:bg-gray-700/60">
            <tr>
              <th class="px-4 py-2 text-left w-10">
                <input type="checkbox" :checked="allOnPageSelected" @change="toggleAllOnPage" />
              </th>
              <th class="px-4 py-2 text-left hidden sm:table-cell">id</th>
              <th class="px-4 py-2 text-left">Nombre</th>
              <th class="px-4 py-2 text-left">Distrito/Iglesia</th>
              <th class="px-4 py-2 text-left hidden md:table-cell">Precio</th>
              <th class="px-4 py-2 text-left hidden md:table-cell">Nom. Director</th>
              <th class="px-4 py-2 text-left">Ultima entrada</th>
              <th class="px-4 py-2 text-left">Ultima salida</th>
            </tr>
          </thead>

          <tbody>
            <tr
              v-for="inscrito in pageRowsSelectedFirst"
              :key="inscrito.id"
              class="odd:bg-gray-50 even:bg-white dark:odd:bg-white/5 dark:even:bg-transparent"
            >
              <td class="px-4 py-2">
                <input
                  type="checkbox"
                  :checked="selectedIds.includes(inscrito.id)"
                  @change="toggleOne(inscrito, $event)"
                />
              </td>
              <td class="px-4 py-2 hidden sm:table-cell">{{ inscrito.id }}</td>
              <td class="px-4 py-2">{{ inscrito.nombre }} <br>
                <small>{{ inscrito.edad }}</small>

              </td>
              <td class="px-4 py-2">
                {{ inscrito.distrito }}<br />
                <small class="text-gray-500 dark:text-gray-400">{{ inscrito.iglesia }}</small>
              </td>
              <td class="px-4 py-2 hidden md:table-cell">{{ inscrito.precio }}</td>
              <td class="px-4 py-2 hidden md:table-cell">
                {{ inscrito.director ?? '-' }}<br />
                <small class="text-gray-500 dark:text-gray-400">
                  {{ inscrito.telefono ?? '-' }}<span v-if="inscrito.telefono && inscrito.email"> | </span>{{ inscrito.email ?? '' }}
                </small>
              </td>
              <td class="px-4 py-2">{{ formatDate(inscrito.last_checkin_entrada?.created_at) }}</td>
              <td class="px-4 py-2">{{ formatDate(inscrito.last_checkin_salida?.created_at) }}</td>
            </tr>

            <tr v-if="props.inscritos.data.length === 0">
              <td colspan="8" class="px-4 py-6 text-center text-gray-500">
                Sin resultados.
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- paginación -->
      <div class="px-4 py-3 border-t border-gray-200 dark:border-gray-700 flex flex-col sm:flex-row gap-4 sm:gap-0 items-center justify-between">
        <div class="text-sm text-gray-500">
          Mostrando {{ props.inscritos.from ?? 0 }}–{{ props.inscritos.to ?? 0 }} de {{ props.inscritos.total }}
        </div>
        <div class="flex flex-wrap justify-center gap-1">
          <Link
            v-for="link in props.inscritos.links"
            :key="link.url ?? link.label"
            :href="link.url || '#'"
            preserve-state
            preserve-scroll
            class="px-3 py-1 rounded-md text-sm"
            :class="[
              link.active
                ? 'bg-indigo-600 text-white border border-indigo-600'
                : 'bg-gray-100 hover:bg-gray-200 text-gray-700 border border-gray-300 dark:bg-gray-700 dark:hover:bg-gray-600 dark:text-gray-100 dark:border-gray-600',
              !link.url && 'opacity-50 pointer-events-none'
            ]"
            v-html="link.label"
          />
        </div>
      </div>
    </div>
  </div>
</template>
