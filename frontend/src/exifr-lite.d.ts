// exifr non dichiara "exports": la versione leggera si importa dal percorso
// del file, con gli stessi tipi del pacchetto.
declare module 'exifr/dist/lite.esm.mjs' {
  export * from 'exifr'
  export { default } from 'exifr'
}
