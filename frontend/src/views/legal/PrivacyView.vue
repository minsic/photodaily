<script setup lang="ts">
import LegalPage from '@/components/LegalPage.vue'
import { legal } from '@/legal'
</script>

<template>
  <LegalPage title="Informativa sulla privacy">
    <p>
      PhotoDaily è un diario fotografico di famiglia: una foto al giorno, con una didascalia, visibile solo a chi
      decide la famiglia. Questa pagina spiega quali dati trattiamo, perché, per quanto tempo e quali diritti hai,
      ai sensi del Regolamento UE 2016/679 (GDPR).
    </p>

    <h2>Titolare del trattamento</h2>
    <p>
      {{ legal.holder }}, P.IVA {{ legal.vatNumber }}, {{ legal.address }}.<br />
      Per qualsiasi domanda sulla privacy scrivi a <a :href="`mailto:${legal.email}`">{{ legal.email }}</a>.
    </p>

    <h2>Quali dati trattiamo</h2>
    <ul>
      <li><strong>Account</strong>: nome, indirizzo email e password (salvata solo in forma cifrata, non leggibile).</li>
      <li>
        <strong>Contenuti del diario</strong>: le foto caricate, le date, le didascalie, il nome della famiglia e,
        se inseriti, nome e data di nascita della persona protagonista del diario.
      </li>
      <li>
        <strong>Metadati delle foto</strong>: la posizione GPS viene tolta dal file al momento del caricamento; teniamo
        la data di scatto per proporre il giorno giusto.
      </li>
      <li><strong>Inviti</strong>: l'indirizzo email delle persone che un amministratore invita nel diario.</li>
      <li><strong>Interazioni</strong>: i cuori messi alle foto dai membri della famiglia.</li>
      <li>
        <strong>Preferenze e notifiche</strong>: orario del promemoria, scelta sul riepilogo settimanale e, se attivi le
        notifiche, l'indirizzo tecnico che il browser fornisce per recapitarle.
      </li>
      <li>
        <strong>Dati tecnici</strong>: indirizzo IP e dati di navigazione registrati dal server per sicurezza e per
        limitare i tentativi di accesso.
      </li>
    </ul>

    <h2>Perché li trattiamo</h2>
    <ul>
      <li>
        Per fornirti il servizio che hai chiesto: creare e mostrare il diario, gestire gli accessi, mandare inviti,
        promemoria e riepiloghi (esecuzione del contratto, art. 6.1.b GDPR).
      </li>
      <li>
        Per proteggere il servizio e gli account da abusi e accessi non autorizzati (legittimo interesse, art. 6.1.f).
      </li>
      <li>Per adempiere a obblighi di legge, per esempio fiscali o richieste dell'autorità (art. 6.1.c).</li>
    </ul>
    <p>Non usiamo i tuoi dati per pubblicità, non li vendiamo e non facciamo profilazione.</p>

    <h2>Foto di minori e di altre persone</h2>
    <p>
      Nel diario compaiono spesso bambini e altre persone. Chi carica una foto dichiara di averne il diritto e, per i
      minori, di essere il genitore o di avere il consenso di chi esercita la responsabilità genitoriale. Il diario è
      privato per impostazione predefinita: renderlo pubblico o proteggerlo con una password è una scelta
      dell'amministratore della famiglia.
    </p>

    <h2>A chi li affidiamo</h2>
    <p>
      I dati restano nei sistemi che usiamo per far funzionare PhotoDaily, gestiti da fornitori nominati responsabili
      del trattamento:
    </p>
    <ul>
      <li>OVHcloud: il server che ospita l'applicazione e il database (data center in Germania).</li>
      <li>Cloudflare: archiviazione delle foto (spazio R2 nell'Unione Europea) e gestione del dominio.</li>
      <li>Brevo: invio delle email (inviti, recupero password, riepiloghi).</li>
      <li>
        Il servizio di notifiche del tuo browser (Apple, Google o Mozilla), solo se attivi i promemoria: riceve un
        messaggio cifrato da consegnare al tuo dispositivo.
      </li>
    </ul>
    <p>
      Alcuni di questi fornitori hanno sede fuori dall'Unione Europea: in quel caso il trasferimento avviene con le
      garanzie previste dal GDPR (decisione di adeguatezza o clausole contrattuali standard).
    </p>

    <h2>Per quanto tempo</h2>
    <ul>
      <li>Account e contenuti: finché il diario resta attivo, o finché non ne chiedi la cancellazione.</li>
      <li>Accessi da un dispositivo: scadono dopo 90 giorni, poi serve rientrare con la password.</li>
      <li>Inviti e link per password o email: validi al massimo pochi giorni, poi non funzionano più.</li>
      <li>Dati tecnici di sicurezza: [DURATA DEI LOG].</li>
    </ul>
    <p>Dopo la cancellazione del diario, foto e dati vengono eliminati entro [TEMPI DI CANCELLAZIONE].</p>

    <h2>I tuoi diritti</h2>
    <p>
      Puoi chiedere in ogni momento di accedere ai tuoi dati, correggerli, cancellarli, limitarne il trattamento,
      riceverli in un formato leggibile (portabilità) o opporti al trattamento basato sul legittimo interesse. Basta
      scrivere a <a :href="`mailto:${legal.email}`">{{ legal.email }}</a>. Nome, password e indirizzo email li puoi
      cambiare anche da solo, dalla pagina del profilo.
    </p>
    <p>
      Se ritieni che il trattamento non sia corretto puoi fare reclamo al Garante per la protezione dei dati personali
      (<a href="https://www.garanteprivacy.it" rel="noopener" target="_blank">garanteprivacy.it</a>).
    </p>

    <h2>Modifiche</h2>
    <p>
      Se questa informativa cambia in modo importante lo scriviamo nell'app o via email prima che le modifiche valgano.
    </p>
  </LegalPage>
</template>
