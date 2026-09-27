<script setup lang="ts">
import { RouterLink } from 'vue-router'

import LegalPage from '@/components/LegalPage.vue'
import { legal } from '@/legal'
</script>

<template>
  <LegalPage title="Termini di servizio">
    <p>
      Questi termini regolano l'uso di PhotoDaily, il servizio offerto da {{ legal.holder }} (P.IVA
      {{ legal.vatNumber }}, {{ legal.address }}). Creando un diario o accettando un invito li accetti.
    </p>

    <h2>Il servizio</h2>
    <p>
      PhotoDaily permette a una famiglia di tenere un diario fotografico: caricare foto con data e didascalia,
      sfogliarle, invitare altre persone e decidere se il diario è privato, protetto da password o pubblico.
    </p>

    <h2>Account e diario</h2>
    <ul>
      <li>Per creare un diario devi avere almeno 18 anni.</li>
      <li>
        Chi crea il diario ne è l'amministratore: invita e rimuove i membri, sceglie chi può vederlo e risponde di chi
        ne fa parte.
      </li>
      <li>Tieni riservata la password e avvisaci subito se pensi che qualcuno l'abbia scoperta.</li>
    </ul>

    <h2>Le tue foto</h2>
    <p>
      Le foto e le didascalie restano tue. Ci concedi soltanto il permesso di conservarle, ridimensionarle e mostrarle
      alle persone che il diario autorizza, e solo per far funzionare il servizio.
    </p>
    <p>
      Ti impegni a caricare solo contenuti su cui hai i diritti, che non violano la legge né la dignità delle persone
      ritratte. Per le foto di minori devi essere il genitore o avere il consenso di chi ne ha la responsabilità.
      Possiamo rimuovere contenuti illeciti che ci vengono segnalati.
    </p>

    <h2>Piani</h2>
    <p>
      Il piano gratuito ha limiti sul numero di foto e sullo spazio, indicati nelle impostazioni del diario. Quando
      saranno disponibili piani a pagamento, prezzi e condizioni saranno mostrati prima dell'acquisto.
    </p>

    <h2>Disponibilità</h2>
    <p>
      Facciamo il possibile perché il servizio sia sempre raggiungibile e le foto al sicuro, ma non possiamo
      garantire che non ci siano mai interruzioni. Ti consigliamo di tenere una copia delle foto a cui tieni di più.
    </p>

    <h2>Sospensione e chiusura</h2>
    <ul>
      <li>
        Puoi smettere di usare PhotoDaily quando vuoi e chiedere la cancellazione del diario scrivendo a
        <a :href="`mailto:${legal.email}`">{{ legal.email }}</a>.
      </li>
      <li>
        Possiamo sospendere un diario che viola questi termini o la legge, avvisando l'amministratore quando è
        possibile.
      </li>
      <li>Se un giorno chiudessimo il servizio, lo comunicheremo con almeno 60 giorni di anticipo.</li>
    </ul>

    <h2>Responsabilità</h2>
    <p>
      Nei limiti consentiti dalla legge non rispondiamo di danni indiretti né di contenuti caricati dagli utenti.
      Restano fermi i diritti che la legge riconosce ai consumatori.
    </p>

    <h2>Privacy</h2>
    <p>
      Come trattiamo i dati personali è spiegato nell'<RouterLink :to="{ name: 'privacy' }">informativa sulla
      privacy</RouterLink>.
    </p>

    <h2>Modifiche ai termini</h2>
    <p>
      Se cambiamo questi termini in modo importante lo comunichiamo almeno 30 giorni prima. Se non sei d'accordo puoi
      chiudere il diario prima che le modifiche valgano.
    </p>

    <h2>Legge applicabile</h2>
    <p>
      Si applica la legge italiana. Per i consumatori è competente il giudice del luogo di residenza; negli altri
      casi il foro di {{ legal.court }}.
    </p>
  </LegalPage>
</template>
