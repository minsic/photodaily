<script setup lang="ts">
/**
 * Come mettere PhotoDaily sulla schermata Home. Installata, l'app si apre a
 * schermo intero e (su Android) compare tra le app a cui condividere le foto.
 */
const installed =
  window.matchMedia('(display-mode: standalone)').matches ||
  (navigator as Navigator & { standalone?: boolean }).standalone === true

const isIos = /iPhone|iPad|iPod/.test(navigator.userAgent) || (navigator.userAgent.includes('Mac') && 'ontouchend' in document)
</script>

<template>
  <section>
    <h2 class="text-lg font-bold">Installa l'app sulla schermata Home</h2>

    <p v-if="installed" class="mt-1 text-sm text-muted">
      Fatto: stai già usando PhotoDaily come app. 🎉
    </p>

    <template v-else>
      <p class="mt-1 text-sm text-muted">
        Così PhotoDaily si apre con un tocco, come le altre app, e caricare la foto del giorno è più
        veloce.
      </p>

      <div class="mt-4 grid gap-4 sm:grid-cols-2">
        <div class="rounded-2xl bg-card p-4 card-shadow" :class="{ 'order-last': isIos }">
          <h3 class="font-bold">Android</h3>
          <ol class="mt-2 list-decimal space-y-1 pl-5 text-sm">
            <li>Apri questa pagina con Chrome.</li>
            <li>Tocca il menu <strong>⋮</strong> in alto a destra.</li>
            <li>Scegli <strong>Installa app</strong> (o <strong>Aggiungi a schermata Home</strong>).</li>
          </ol>
          <p class="mt-2 text-sm text-muted">
            Poi dalla galleria puoi toccare <strong>Condividi</strong> e scegliere PhotoDaily.
          </p>
        </div>

        <div class="rounded-2xl bg-card p-4 card-shadow">
          <h3 class="font-bold">iPhone</h3>
          <ol class="mt-2 list-decimal space-y-1 pl-5 text-sm">
            <li>Apri questa pagina con Safari.</li>
            <li>Tocca <strong>Condividi</strong> (il quadrato con la freccia in su).</li>
            <li>Scegli <strong>Aggiungi alla schermata Home</strong>.</li>
          </ol>
          <p class="mt-2 text-sm text-muted">
            Su iPhone non si può condividere dalla galleria a PhotoDaily: usa il pulsante
            <strong>Carica</strong>, che apre subito foto e fotocamera.
          </p>
        </div>
      </div>
    </template>
  </section>
</template>
