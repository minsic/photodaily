<script setup lang="ts">
import { RouterLink } from 'vue-router'

import LegalPage from '@/components/LegalPage.vue'
</script>

<template>
  <LegalPage title="Cookie e memoria del browser">
    <p>
      PhotoDaily non usa cookie di profilazione, di pubblicità o di statistica, e non carica strumenti di terze parti
      che ti seguono tra i siti. Per questo non ti chiediamo il consenso con un banner.
    </p>

    <h2>Cosa salviamo nel tuo browser</h2>
    <p>Per funzionare l'app tiene alcune informazioni tecniche nella memoria locale del browser (localStorage e IndexedDB):</p>
    <ul>
      <li>il codice di accesso, per non chiederti la password a ogni apertura;</li>
      <li>la vista che preferisci (timeline, mese o anno) e piccole preferenze di visualizzazione;</li>
      <li>per un diario protetto da password, lo sblocco già fatto su questo dispositivo;</li>
      <li>le foto condivise da un'altra app, finché non le hai caricate.</li>
    </ul>
    <p>
      Sono strumenti strettamente necessari al servizio che hai chiesto (art. 122 del Codice privacy) e non vengono
      letti da nessun altro. Uscendo dall'account il codice di accesso viene cancellato; il resto puoi toglierlo
      cancellando i dati del sito dalle impostazioni del browser.
    </p>

    <h2>Per saperne di più</h2>
    <p>
      Tutto quello che riguarda i tuoi dati personali è nell'<RouterLink :to="{ name: 'privacy' }">informativa sulla
      privacy</RouterLink>.
    </p>
  </LegalPage>
</template>
