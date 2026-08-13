# MES — Guida semplice

Guida pratica al modulo di produzione (MES). Spiega il ciclo di lavoro senza
tecnicismi, nell'ordine in cui lo useresti davvero.

## In breve

Il MES trasforma **cosa produrre** in **produzione eseguibile**: prepari le
anagrafiche (centri di lavoro, distinte, cicli), apri un **ordine di
produzione**, avanzi le operazioni sullo shop floor, e il sistema consuma i
materiali, genera i lotti e calcola gli indicatori (efficienza, OEE).

## 1. Centro di lavoro

È dove si lavora: una macchina, una cella, una linea o una postazione manuale.
Ha un **codice** (unico per azienda), una **capacità oraria** e un calendario di
disponibilità. Crea prima i centri di lavoro: tutto il resto vi si appoggia.

## 2. Distinta base (BOM)

Elenca i **componenti** che servono per fabbricare un articolo, con quantità e
unità di misura. Ogni riga indica come si consuma il componente:

- **Backflush**: consumato automaticamente quando l'operazione collegata è
  completata.
- **Manuale**: consumato con una registrazione dell'operatore.

Una distinta ha una **validità** (da/a): il sistema usa sempre la versione
attiva alla data.

## 3. Ciclo di lavorazione (Routing)

È la **sequenza di operazioni** per produrre l'articolo: per ciascuna, il centro
di lavoro, il tempo di setup e il tempo ciclo. Anche il ciclo ha una validità.

## 4. Ordine di produzione

Quando decidi di produrre, crei un **ordine**: articolo, quantità, magazzino e
date pianificate. Alla creazione il sistema:

- assegna un **numero** progressivo,
- **congela** la distinta e il ciclo attivi in una fotografia immutabile: se poi
  modifichi distinta o ciclo, l'ordine già creato non cambia.

L'ordine nasce in stato **bozza**.

## 5. Avanzamento

- **Rilascia** l'ordine: passa a *rilasciato* e vengono generate le operazioni
  da eseguire.
- **Avvia** e **completa** ogni operazione. Al completamento:
  - viene registrato il log dell'operatore (con avviso non bloccante se non c'è
    un turno attivo),
  - vengono **consumati i materiali in backflush** collegati,
  - viene calcolata l'**efficienza** (tempo standard rispetto al tempo reale).
- **Completa** l'ordine indicando la quantità prodotta. Se l'articolo è tracciato
  a **lotto/seriale**, viene generato automaticamente il lotto del prodotto.

## 6. Qualità

Su un ordine puoi eseguire un **controllo qualità** con misure e limiti. Se una
misura è fuori tolleranza, il controllo risulta **fallito** e si apre una **non
conformità**. La non conformità si **risolve** scegliendo una disposizione:
scarto, rilavorazione (crea un nuovo ordine di rilavorazione collegato), uso in
deroga o reso a fornitore; infine si **chiude**.

## 7. Tracciabilità

Ogni lotto sa **da cosa proviene** (trace all'indietro) e **dove è finito**
(trace in avanti), seguendo la genealogia dei lotti. Utile per richiami e
controlli.

## 8. Fermi e OEE

Registri i **fermi macchina** (guasto, setup, cambio, mancanza materiale, ecc.)
con inizio e fine. L'**OEE** riassume l'efficacia dell'impianto come prodotto di
tre fattori — **Disponibilità × Prestazione × Qualità** — sempre tra 0 e 1.

## 9. Turni e operatori

Definisci i **turni** e le loro **istanze giornaliere**. Ogni avvio/completamento
operazione produce un **log operatore**; da qui si ricava l'efficienza media per
operatore o per turno. La mancanza di turno è solo un avviso, non blocca il
lavoro.

## Dove si vede

Gli indicatori principali (ordini aperti, operazioni in corso, non conformità
aperte) sono nel **widget dashboard di produzione** del pannello Filament.
