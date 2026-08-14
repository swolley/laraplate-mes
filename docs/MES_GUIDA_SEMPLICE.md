# MES — Guida semplice

Guida pratica al modulo di produzione (MES). Spiega il ciclo di lavoro senza
tecnicismi, nell'ordine in cui lo useresti davvero.

## In breve

Il MES trasforma **cosa produrre** in **produzione eseguibile**: prepari le
anagrafiche (centri di lavoro, distinte, cicli), apri un **ordine di
produzione** — anche in automatico da un ordine di vendita confermato — avanzi le
operazioni sullo shop floor, e il sistema consuma i materiali, genera i lotti,
crea i controlli qualità previsti e calcola gli indicatori (efficienza, OEE).

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

### Creazione automatica da ordine di vendita

Non sempre devi creare gli ordini a mano: quando un **ordine di vendita** viene
**confermato**, il sistema crea in automatico un ordine di produzione (in bozza)
per ogni riga il cui articolo ha una **distinta attiva** (cioè è un articolo da
fabbricare). Le righe di acquisto/servizio, o già consegnate, vengono ignorate.
La quantità pianificata è quella **ancora da produrre** (ordinata meno già
consegnata), il magazzino è quello configurato per l'azienda (o l'unico presente)
e le date si stimano dal ciclo di lavorazione. Ogni riga genera al massimo un
ordine: riconfermare l'ordine di vendita non crea duplicati.

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

### Se manca materiale

Quando un consumo (backflush o manuale) richiede più di quanto disponibile a
magazzino, il sistema **non si blocca**: scarica **ciò che c'è**, registra la
parte mancante come **carenza** (con lo scostamento) e invia una **notifica** ai
responsabili configurati. La giacenza resta corretta e non va mai sotto zero; la
carenza resta tracciata per il riassortimento e la riconciliazione.

## 6. Qualità

### Piani di qualità e controlli automatici

Puoi definire un **piano di qualità** per un articolo, con le caratteristiche
attese e le relative tolleranze (nominale, minimo, massimo). Il piano può essere
legato a una **operazione del ciclo** (controllo in produzione) oppure
all'articolo finito senza operazione (**collaudo finale**). Quando l'operazione o
l'ordine si completano, il sistema **crea automaticamente** i controlli previsti
dal piano attivo, in stato *da eseguire*. Non è bloccante: la produzione prosegue
e i controlli restano in attesa dell'operatore.

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
aperte) sono nel **widget dashboard di produzione** del pannello Filament. Le
anagrafiche e gli ordini si gestiscono dalle relative sezioni del pannello
(inclusi i **piani di qualità**). Le **carenze di materiale** arrivano come
notifica in-app ai responsabili configurati.
