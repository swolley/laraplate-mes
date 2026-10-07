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
Ha un **codice** (unico per azienda: un secondo centro con lo stesso codice viene
rifiutato), una **capacità oraria** e un calendario settimanale di disponibilità
(giorno, ora di inizio e di fine), che compili direttamente nella scheda del
centro. Un centro che non usi più si **disattiva**. Crea prima i centri di lavoro:
tutto il resto vi si appoggia.

## 2. Distinta base (BOM)

Elenca i **componenti** che servono per fabbricare un articolo, con quantità e
unità di misura. Ogni riga indica come si consuma il componente:

- **Backflush**: consumato automaticamente quando l'operazione collegata è
  completata.
- **Manuale**: consumato con una registrazione dell'operatore.

Una distinta ha una **validità** (da/a): il sistema usa sempre la versione
attiva alla data.

Puoi modificare una distinta in qualsiasi momento: gli ordini già creati non
cambiano, perché hanno la propria fotografia. Ogni modifica, comprese quelle alle
righe, viene registrata in uno **storico** (con le impostazioni predefinite), anche quando una riga viene eliminata
(l'eliminazione è definitiva, ma nello storico resta l'immagine della riga). Le
righe con quantità nulla o negativa, o con un'unità di misura troppo lunga,
vengono rifiutate.

## 3. Ciclo di lavorazione (Routing)

È la **sequenza di operazioni** per produrre l'articolo: per ciascuna, il centro
di lavoro, il tempo di setup e il tempo ciclo. Anche il ciclo ha una validità.

Valgono le stesse regole della distinta: le modifiche al ciclo e alle sue
operazioni finiscono nello storico, e tempi negativi o una descrizione vuota
vengono rifiutati.

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
- **Completa** l'ordine indicando la quantità prodotta. Se un'operazione è
  ancora **in corso**, il completamento viene rifiutato: prima chiudila (o
  saltala). Se l'articolo è tracciato a **lotto/seriale**, viene generato
  automaticamente il lotto del prodotto.

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

I fermi non programmati riducono anche il **tempo disponibile** del centro di
lavoro: il sistema lo usa per capire se il carico pianificato in un periodo
supera ciò che il centro può fare (sovraccarico). La manutenzione programmata non
conta, come nell'OEE.

## 9. Turni e operatori

Definisci i **turni** e le loro **istanze giornaliere**. Ogni avvio/completamento
operazione produce un **log operatore**; da qui si ricava l'efficienza media per
operatore o per turno. La mancanza di turno è solo un avviso, non blocca il
lavoro.

## Dove si vede

Il pannello Filament è il **backoffice**: serve a configurare e a controllare,
non a lavorare l'ordine tutti i giorni.

- Dal pannello gestisci le **anagrafiche**: centri di lavoro (con il loro
  calendario), distinte (con le righe dei componenti), cicli, **piani di
  qualità** e turni. Puoi anche consultare fermi, controlli qualità e non
  conformità.
- Gli **ordini di produzione** nel pannello si consultano: per ciascuno vedi le
  operazioni, i consumi di materiale, i controlli qualità e i lotti prodotti, in
  sola lettura. Dalla pagina dell'ordine puoi **rilasciare**, **completare**
  (indicando la quantità prodotta e, se serve, il lotto) e **annullare**, con le
  stesse regole descritte sopra; l'avanzamento delle operazioni passa invece
  dall'applicazione di produzione (o dalle chiamate API).
- Il **widget dashboard di produzione** mostra quattro conteggi: ordini aperti,
  operazioni in corso, ordini completati e non conformità aperte.
- L'**OEE** e il **carico dei centri di lavoro** vengono calcolati dal sistema su
  richiesta, ma per ora **non sono mostrati** in nessuna schermata.
- Le **carenze di materiale** arrivano come notifica in-app ai responsabili
  configurati.

## Collegare le macchine

Le macchine e i sistemi di misura possono mandare i loro dati direttamente al MES, senza che qualcuno li
digiti. Il MES li **riceve, li conserva** e usa lo stato della macchina per aprire i fermi , i contapezzi per le quantità
prodotte e le sonde per i controlli qualità; non salva ancora i valori di processo (arriverà nel passo successivo).

- Dal gruppo **Machine connectivity** del pannello crei una **sorgente** (l'agente o il gateway che invia
  i dati) e le assegni un **token**: viene mostrato **una sola volta**, copialo subito; emetterne uno nuovo
  revoca il precedente.
- Una **macchina** (dispositivo) è legata a un centro di lavoro e ha dei **segnali**, ciascuno con un ruolo
  (stato, allarme, pezzi buoni, pezzi scarto, misura, valore di processo...). Un **profilo** applicato alla
  macchina le copia i segnali di un modello noto; i profili si importano ed esportano come file.
- Ciò che la macchina manda e nessuno ha configurato finisce nei **segnali non mappati**: da lì lo mappi
  con un clic. Un valore di stato non previsto dalla mappa compare come `segnale#VALORE`: si corregge nella
  mappa del segnale di stato, poi si rielabora.
- La **posta in arrivo** mostra ogni messaggio ricevuto e il suo esito; un messaggio fallito si può
  **rielaborare**, anche per un intervallo di tempo dalla pagina della sorgente.
- Gli **incidenti** segnalano buchi nella sequenza dei messaggi, orologi sfasati, messaggi falliti,
  accessi rifiutati e macchine che hanno smesso di inviare dati.

### Fermi e OEE dalle macchine

Un centro di lavoro con una macchina attiva che manda lo **stato** è "collegato": i suoi fermi li apre e li
chiude la macchina, e non si possono inserire a mano (il sistema lo rifiuta). Un arresto diventa un fermo solo
se dura **più** della **soglia dei micro-fermi** (60 secondi, modificabile nel centro di lavoro) e se lo stato
è tra quelli che contano come fermo (anche questi si scelgono nel centro di lavoro). Gli orari del fermo
vengono dalla macchina e non si cambiano; **causa e note** sì: finché non le imposti la causa è
**unclassified**, salvo che un codice di allarme o lo stato (setup, manutenzione) la indichi. Se la macchina smette di inviare dati compare
uno stato **Offline**: non è un fermo, ma l'OEE del giorno mostra **(incomplete)** perché i dati sono
incompleti. La disponibilità dell'OEE per i centri collegati segue lo standard ISO 22400: la manutenzione
programmata non conta come perdita.

### Misure delle sonde

Una sonda collegata a una caratteristica del piano qualità manda le sue misure al controllo qualità
dell'operazione: i limiti sono quelli del piano, e il controllo si chiude da solo (superato o non superato)
quando ogni caratteristica ha le misure richieste (**campioni richiesti**, uno per default). Un valore fuori
limite fa partire subito una notifica, anche prima che il controllo si chiuda; un valore fuori limite che arriva
a controllo già chiuso apre una non conformità. Il controllo di un'operazione nasce quando l'operazione si
chiude: le misure fatte prima aspettano in **Unattributed measurements** e si agganciano da sole al controllo
appena viene creato. Quelle che restano lì (nessuna operazione, nessun controllo) le assegni a mano con
**Assign**, scegliendo il controllo.

### Pezzi contati

Se la macchina manda i contapezzi (buoni, scarti, totale), ogni operazione mostra le quantità **della
macchina** (buoni e scarti). Quando i pezzi buoni raggiungono la quantità pianificata dell'ordine arriva una
notifica, una sola volta: l'operazione però **non** si chiude da sola. Alla chiusura le quantità **dichiarate**
partono da quelle della macchina; puoi correggerle con **Declare quantities** nella tabella delle operazioni e
ogni correzione resta registrata (chi, prima, dopo). I pezzi contati senza un'operazione restano sul centro di
lavoro e contano per l'OEE: con **Assign machine counts** li assegni a un'operazione indicando l'intervallo di
tempo. Con i contapezzi, **prestazione** e **qualità** dell'OEE si calcolano sui pezzi contati (ISO 22400).

### Le macchine che parlano tramite MQTT

Se le macchine mandano i dati a un broker MQTT (il vostro, non incluso nel MES), nella sorgente scegli il
trasporto **mqtt** e indichi l'argomento (topic) su cui pubblica; per le sorgenti che usano il formato
standard senza altre indicazioni l'argomento si ricava dal codice della sorgente. Un servizio sempre acceso
(`mes:machine-bridge`) ascolta il broker e passa i messaggi al MES: se si ferma, dopo un minuto compare un
incidente **bridge_down** e i dati restano sul broker finché il servizio non riparte. Per le macchine che
usano **Sparkplug B**, i segnali di una macchina diventano disponibili dopo la sua "nascita": se arrivano dati
prima, li trovi tra i segnali non mappati come `alias#N` e basta rielaborare il messaggio dopo la nascita.
