<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';

type Team = { id: number; naam: string; code: string };
type FunctieSoort = { id: number; naam: string; code: string };
type Medewerker = {
  id: number;
  persoon_naam: string | null;
  medewerker_nummer: string;
  team_id: number | null;
  team_naam: string | null;
  user_id: number | null;
  user_email: string | null;
  functie_soort_id: number | null;
  functie_soort_naam: string | null;
};
type Persoon = {
  id: number;
  naam: string;
  medewerker_id: number | null;
  medewerker_nummer: string | null;
  team_id: number | null;
  team_naam: string | null;
  functie_soort_id: number | null;
  functie_soort_naam: string | null;
};
type User = { id: number; name: string; email: string };

defineProps<{
  teams: Team[];
  functieSoorten: FunctieSoort[];
  medewerkers: Medewerker[];
  personen: Persoon[];
  users: User[];
}>();

const newTeam = useForm({ naam: '', code: '' });
const newPersoon = useForm({ naam: '' });

const teamEdit = useForm({ id: null as number | null, naam: '', code: '' });
const medewerkerEdit = useForm({
  id: null as number | null,
  medewerker_nummer: '',
  team_id: null as number | null,
  user_id: null as number | null,
  functie_soort_id: null as number | null,
});
const persoonEdit = useForm({
  id: null as number | null,
  naam: '',
  medewerker_nummer: '',
  team_id: null as number | null,
  functie_soort_id: null as number | null,
});

const openTeamEdit = (team: Team) => {
  teamEdit.id = team.id;
  teamEdit.naam = team.naam;
  teamEdit.code = team.code;
};

const cancelTeamEdit = () => {
  teamEdit.id = null;
  teamEdit.reset();
  teamEdit.clearErrors();
};

const openMedewerkerEdit = (m: Medewerker) => {
  medewerkerEdit.id = m.id;
  medewerkerEdit.medewerker_nummer = m.medewerker_nummer;
  medewerkerEdit.team_id = m.team_id;
  medewerkerEdit.user_id = m.user_id;
  medewerkerEdit.functie_soort_id = m.functie_soort_id;
};

const cancelMedewerkerEdit = () => {
  medewerkerEdit.id = null;
  medewerkerEdit.reset();
  medewerkerEdit.clearErrors();
};

const openPersoonEdit = (p: Persoon) => {
  persoonEdit.id = p.id;
  persoonEdit.naam = p.naam;
  persoonEdit.medewerker_nummer = p.medewerker_nummer ?? '';
  persoonEdit.team_id = p.team_id;
  persoonEdit.functie_soort_id = p.functie_soort_id;
};

const cancelPersoonEdit = () => {
  persoonEdit.id = null;
  persoonEdit.reset();
  persoonEdit.clearErrors();
};

const isMedewerkerInvoer = () => persoonEdit.medewerker_nummer.trim() !== '';
</script>

<template>
  <Head title="Beheer" />

  <div class="flex h-full flex-1 flex-col gap-6 rounded-xl p-4">
    <div class="rounded-2xl border border-sidebar-border/70 bg-gradient-to-br from-white via-white to-cyan-50 px-6 py-5 shadow-sm dark:border-sidebar-border dark:from-gray-900 dark:via-gray-900 dark:to-gray-800">
      <h1 class="text-2xl font-semibold tracking-tight text-gray-900 dark:text-white">Beheer</h1>
      <p class="text-sm text-gray-600 dark:text-gray-300">Teams, medewerkers en personen beheren.</p>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
      <section class="rounded-2xl border border-sidebar-border/70 bg-white p-5 shadow-sm dark:border-sidebar-border dark:bg-gray-900">
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Teams</h2>
        <form class="mt-4 space-y-2" @submit.prevent="newTeam.post('/beheer/teams')">
          <input v-model="newTeam.naam" class="h-10 w-full rounded border px-3" placeholder="Naam" required>
          <input v-model="newTeam.code" class="h-10 w-full rounded border px-3" placeholder="Code" required>
          <button class="rounded bg-cyan-700 px-3 py-2 text-sm font-semibold text-white" :disabled="newTeam.processing">Team toevoegen</button>
        </form>

        <div class="mt-4 space-y-2">
          <button
            v-for="team in teams"
            :key="team.id"
            class="w-full rounded border px-3 py-2 text-left text-sm"
            @click="openTeamEdit(team)"
          >
            {{ team.naam }} ({{ team.code }})
          </button>
        </div>

        <form v-if="teamEdit.id" class="mt-4 space-y-2 border-t pt-4" @submit.prevent="teamEdit.patch(`/beheer/teams/${teamEdit.id}`)">
          <input v-model="teamEdit.naam" class="h-10 w-full rounded border px-3" required>
          <input v-model="teamEdit.code" class="h-10 w-full rounded border px-3" required>
          <div class="flex items-center gap-2">
            <button class="rounded bg-gray-800 px-3 py-2 text-sm font-semibold text-white" :disabled="teamEdit.processing">Opslaan</button>
            <button type="button" class="rounded border px-3 py-2 text-sm font-semibold" @click="cancelTeamEdit">Annuleren</button>
          </div>
        </form>
      </section>

      <section class="rounded-2xl border border-sidebar-border/70 bg-white p-5 shadow-sm dark:border-sidebar-border dark:bg-gray-900">
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Medewerkers</h2>
        <div class="mt-4 space-y-2">
          <button
            v-for="m in medewerkers"
            :key="m.id"
            class="w-full rounded border px-3 py-2 text-left text-sm"
            @click="openMedewerkerEdit(m)"
          >
            {{ m.persoon_naam ?? 'zonder persoon' }} · {{ m.medewerker_nummer }} · {{ m.team_naam ?? 'geen team' }}
          </button>
        </div>

        <form v-if="medewerkerEdit.id" class="mt-4 space-y-2 border-t pt-4" @submit.prevent="medewerkerEdit.patch(`/beheer/medewerkers/${medewerkerEdit.id}`)">
          <input v-model="medewerkerEdit.medewerker_nummer" class="h-10 w-full rounded border px-3" required>
          <select v-model="medewerkerEdit.functie_soort_id" class="h-10 w-full rounded border px-3" required>
            <option :value="null" disabled>Kies functie</option>
            <option v-for="f in functieSoorten" :key="f.id" :value="f.id">{{ f.naam }}</option>
          </select>
          <select v-model="medewerkerEdit.team_id" class="h-10 w-full rounded border px-3">
            <option :value="null">Geen team</option>
            <option v-for="team in teams" :key="team.id" :value="team.id">{{ team.naam }}</option>
          </select>
          <select v-model="medewerkerEdit.user_id" class="h-10 w-full rounded border px-3">
            <option :value="null">Geen user</option>
            <option v-for="u in users" :key="u.id" :value="u.id">#{{ u.id }} · {{ u.email }}</option>
          </select>
          <div class="flex items-center gap-2">
            <button class="rounded bg-gray-800 px-3 py-2 text-sm font-semibold text-white" :disabled="medewerkerEdit.processing">Opslaan</button>
            <button type="button" class="rounded border px-3 py-2 text-sm font-semibold" @click="cancelMedewerkerEdit">Annuleren</button>
          </div>
        </form>
      </section>

      <section class="rounded-2xl border border-sidebar-border/70 bg-white p-5 shadow-sm dark:border-sidebar-border dark:bg-gray-900">
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Personen</h2>
        <form class="mt-4 space-y-2" @submit.prevent="newPersoon.post('/beheer/personen')">
          <input v-model="newPersoon.naam" class="h-10 w-full rounded border px-3" placeholder="Naam" required>
          <button class="rounded bg-cyan-700 px-3 py-2 text-sm font-semibold text-white" :disabled="newPersoon.processing">Persoon toevoegen</button>
        </form>

        <div class="mt-4 space-y-2">
          <div
            v-for="p in personen"
            :key="p.id"
            class="rounded border px-3 py-2 text-sm"
          >
            <div class="font-medium">{{ p.naam }}</div>
            <div class="mt-1 text-xs" :class="p.medewerker_nummer ? 'text-emerald-700' : 'text-amber-700'">
              {{ p.medewerker_nummer ? `Medewerker: ${p.medewerker_nummer}` : 'Nog geen medewerker' }}
            </div>
            <div v-if="p.medewerker_nummer" class="text-xs text-gray-500">
              Functie: {{ p.functie_soort_naam ?? 'onbekend' }} · Team: {{ p.team_naam ?? 'geen' }}
            </div>
            <div class="mt-2 flex gap-2">
              <button class="rounded bg-gray-800 px-2 py-1 text-xs font-semibold text-white" @click="openPersoonEdit(p)">Bewerk persoon</button>
            </div>
          </div>
        </div>

        <form v-if="persoonEdit.id" class="mt-4 space-y-2 border-t pt-4" @submit.prevent="persoonEdit.patch(`/beheer/personen/${persoonEdit.id}`)">
          <input v-model="persoonEdit.naam" class="h-10 w-full rounded border px-3" required>
          <input v-model="persoonEdit.medewerker_nummer" class="h-10 w-full rounded border px-3" placeholder="Medewerkernummer (leeg = geen medewerker)">
          <select v-model="persoonEdit.functie_soort_id" class="h-10 w-full rounded border px-3" :required="isMedewerkerInvoer()">
            <option :value="null">Kies functie</option>
            <option v-for="f in functieSoorten" :key="f.id" :value="f.id">{{ f.naam }}</option>
          </select>
          <select v-model="persoonEdit.team_id" class="h-10 w-full rounded border px-3">
            <option :value="null">Geen team</option>
            <option v-for="team in teams" :key="team.id" :value="team.id">{{ team.naam }}</option>
          </select>
          <div class="flex items-center gap-2">
            <button class="rounded bg-gray-800 px-3 py-2 text-sm font-semibold text-white" :disabled="persoonEdit.processing">Opslaan</button>
            <button type="button" class="rounded border px-3 py-2 text-sm font-semibold" @click="cancelPersoonEdit">Annuleren</button>
          </div>
        </form>
      </section>
    </div>
  </div>
</template>
