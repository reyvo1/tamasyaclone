import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
const read=p=>fs.readFileSync(new URL('../'+p,import.meta.url),'utf8');
const window={TAMASYA_RUNTIME_CONFIG:{propertyTimezone:'Asia/Makassar'}};
vm.runInNewContext(read('assets/canonical-business-policy.js'),{window,Intl,Date});
vm.runInNewContext(read('assets/currency-display.js'),{window,Intl});
const policy=window.TAMASYA_BUSINESS_POLICY;
let passed=0;
const check=(name,fn)=>{fn();passed++;console.log('PASS '+name);};
const receipt=(id,amount,extra={})=>({id,type:'income',date:'2026-01-28',amount,baseAmount:amount/1.1,taxAmount:amount-amount/1.1,taxSnapshotStatus:'confirmed',transactionKind:'booking_payment',categorySystemKey:'room_rental',...extra});
check('Annual target excludes other years, expenses, opening capital, OTA payout, deposits and tax suspense',()=>{
 const rows=[receipt('known',110000),receipt('prior',220000,{date:'2025-12-31'}),receipt('next',330000,{date:'2027-01-01'}),receipt('future',220000,{date:'2026-12-31'}),receipt('refund',11000,{type:'expense',transactionKind:'refund'}),receipt('unknown',33000,{taxSnapshotStatus:'unresolved',baseAmount:null,taxAmount:null}),receipt('capital',1e8,{transactionKind:'opening_balance_cash'}),receipt('deposit',1e6,{transactionKind:'security_deposit_received'}),receipt('payout',1e6,{transactionKind:'ota_transfer'})];
 const target=policy.annualRevenueTarget(rows,{targetInvestment:1000000},new Date('2026-02-01T01:00:00Z'));
 assert.equal(target.year,'2026');assert.equal(target.actual,90000);assert.equal(target.percent,9);assert.equal(target.unresolved,1);
 assert.equal(policy.annualRevenueTarget(rows,{targetInvestment:0}).percent,null);
});
check('Annual boundaries follow property timezone rather than device UTC year',()=>{
 const target=policy.annualRevenueTarget([receipt('year-start',110000,{date:'2026-01-01'})],{targetInvestment:100000},new Date('2025-12-31T17:00:00Z'));
 assert.equal(target.year,'2026');assert.equal(target.actual,100000);assert.equal(target.percent,100);
});
check('Cash, bank, combined balance and gross receipts reconcile across split and internal transfers',()=>{
 const rows=[receipt('cash',110000),receipt('bank',220000,{bankAccountId:'bank'}),receipt('split',330000,{isSplitPayment:true,splitCashAmount:100000,splitTransferAmount:230000,splitTransferBankAccountId:'bank'}),receipt('out',150000,{type:'expense',transactionKind:'manual',taxSnapshotStatus:'not_applicable'}),receipt('to-bank-out',50000,{type:'expense',transactionKind:'internal_transfer'}),receipt('to-bank-in',50000,{bankAccountId:'bank',transactionKind:'internal_transfer'}),receipt('forfeit',90000,{transactionKind:'security_deposit_forfeit'}),receipt('ota',550000,{bankAccountId:'ota_receivable'})];
 const summary=policy.financialDisplaySummary(rows,{initialCash:20000});
 assert.equal(summary.receipts,660000);assert.equal(summary.payments,150000);assert.equal(summary.cashBalance,30000);assert.equal(summary.bankBalance,500000);assert.equal(summary.combinedBalance,530000);
 assert.equal(summary.combinedBalance,summary.initialCash+summary.netMovement);
 assert.equal(summary.combinedBalance,summary.cashBalance+Object.values(summary.bankBalances).reduce((a,b)=>a+b,0));
});
check('Dashboard uses shipped shared summary and omits opening cash from the receipts graph',()=>{
 const feature=read('assets/chunks/feature-shared.js');
 const jfSource=feature.slice(feature.indexOf('function jf('),feature.indexOf('function sp('));
 const jf=new Function(jfSource+';return jf;')();
 const start=feature.indexOf('function i8('),end=feature.indexOf('\n',start);
 const summary=new Function('window','ls','Lu','jf','KA','xh','gh','eN','r8','zA',feature.slice(start,end)+';return i8;')(window,()=> '2026-02-01',()=> 'available',jf,t=>policy.transactionSemantics(t).isDepositForfeit,t=>policy.transactionSemantics(t).isLiquidExternalIncome,t=>policy.transactionSemantics(t).isLiquidExternalExpense,t=>{const sem=policy.transactionSemantics(t);return sem.isInternalTransfer&&!sem.isOtaTransfer;},x=>x,()=> '2026-02');
 const result=summary({rooms:[],bookings:[],transactions:[receipt('revenue',110000),receipt('opening',1000000,{transactionKind:'opening_balance_cash'})]});
 assert.equal(result.totalCashIncome,110000);assert.equal(result.liquidDelta,1110000);assert.equal(result.recognizedNetIncome,100000);assert.equal(result.monthlyCashData[0].Pemasukan,110000);
});
check('Shared inventory estimate keeps undated acquisition value visible, agrees at five years, and uses as-of date',()=>{
 assert.equal(policy.estimatedInventoryValue({price:1000000},'2026-01-01').bookValue,1000000);
 assert.equal(policy.estimatedInventoryValue({price:1000000},'2026-01-01').missingDate,true);
 assert.equal(policy.estimatedInventoryValue({price:1000000,purchase_date:'2021-01-01'},'2026-01-01').bookValue,100000);
 assert.equal(policy.estimatedInventoryValue({price:1000000,purchase_date:'2026-02-01'},'2026-01-01').bookValue,0);
 assert.equal(policy.estimatedInventoryValue({price:1000000,purchase_date:'2026-02-30'},'2026-03-01').missingDate,true);
 const report=read('assets/chunks/report.js'),inventory=read('assets/chunks/inventory.js');
 assert.ok(report.includes('estimatedInventoryValue(ge,zt)'));assert.ok(inventory.includes('estimatedInventoryValue(P)'));
});
check('Shift variance only includes closed shifts in the property current month',()=>{
 const rows=[{shift_date:'2026-02-01',status:'closed',variance:-10000},{shift_date:'2026-02-02',status:'closed',variance:3000},{shift_date:'2026-01-01',status:'closed',variance:999999},{shift_date:'2026-02-01',status:'open',variance:9000}];
 assert.equal(policy.monthlyShiftVariance(rows,new Date('2026-02-05T00:00:00Z')),-7000);
});
check('Saved tax remains unknown until supported by a valid snapshot',()=>{
 assert.equal(policy.savedTaxAmount(receipt('unknown',110000,{taxSnapshotStatus:'unresolved',baseAmount:null,taxAmount:null})),null);
 assert.equal(Math.round(policy.savedTaxAmount(receipt('known',110000))),10000);
 assert.equal(policy.savedTaxAmount(receipt('invalid',110000,{taxAmount:99})),null);
});
check('Whole-rupiah UI retains exact underlying money and property-date month defaults',()=>{
 const feature=read('assets/chunks/feature-shared.js');
 const start=feature.indexOf('function Ve('),end=feature.indexOf('function t8(',start);
 const format=new Function('window',feature.slice(start,end)+';return Ve;')(window);
 assert.equal(format(250000.30),'Rp 250.000');assert.equal(window.TamasyaCurrencyDisplay.formatRupiahExact(250000.30),'Rp 250.000,30');
 const shared=read('assets/chunks/app-shared.js');const dateStart=shared.indexOf('function ls('),dateEnd=shared.indexOf('function Mh(',dateStart);
 const dateAt=new Function('window',shared.slice(dateStart,dateEnd)+';return ls;')(window);
 assert.equal(dateAt(new Date('2026-01-31T17:00:00Z')),'2026-02-01');
 const summary=policy.financialDisplaySummary([receipt('fraction',110000.30)]);
 assert.equal(summary.combinedBalance,110000.30);
});
check('Finance daily graph includes more than 15 dates instead of silently truncating the selected period',()=>{
 const source=read('assets/chunks/finance.js');
 const start=source.indexOf('Zd=()=>'),end=source.indexOf(',tm=ed()',start);
 const calculate=new Function('wi','tamasyaGraphLeg','xh','gh',source.slice(start,end)+';return Zd;')(Array.from({length:25},(_,i)=>({date:`2026-01-${String(i+1).padStart(2,'0')}`,amount:1000,type:'income'})),t=>t.amount,t=>t.type==='income',t=>t.type==='expense');
 assert.equal(calculate().length,25);
});
check('Leave starts on its first property calendar day and ends after its final day',()=>{
 const source=read('assets/chunks/leaves.js'),start=source.indexOf('R=(ce,we)=>'),end=source.indexOf(',pe=ce=>',start);
 let today='2026-01-01';const status=new Function('window','const '+source.slice(start,end)+';return R;')({TAMASYA_BUSINESS_POLICY:{propertyDate:()=>today}});
 assert.equal(status('2026-01-01','2026-01-03').code,'on_leave');
 today='2026-01-03';assert.equal(status('2026-01-01','2026-01-03').code,'on_leave');
 today='2026-01-04';assert.equal(status('2026-01-01','2026-01-03').code,'completed');
});
check('Local operational summary counts dirty rooms separately from available rooms',()=>{
 const source=read('assets/chunks/local-connect.js'),start=source.indexOf('R=f.useMemo('),end=source.indexOf(',pe=',start);
 const summary=new Function('f','e','X','zo','Lu','const '+source.slice(start,end)+';return R;')({useMemo:fn=>fn()},{rooms:[{status:'dirty'},{status:'available'},{status:'booked'}],bookings:[]},[],String,room=>room.status);
 assert.equal(summary.dirty,1);assert.equal(summary.available,1);assert.equal(summary.booked,1);
 assert.equal(Object.values(summary).reduce((a,b)=>a+b,0),3);
});
check('Client recognizedRevenue matches server: valid split takes precedence over unresolved status',()=>{
 const tx={id:'unresolved-split',type:'income',date:'2026-01-28',amount:110000,baseAmount:100000,taxAmount:10000,taxSnapshotStatus:'unresolved',transactionKind:'booking_payment',categorySystemKey:'room_rental'};
 assert.equal(Math.round(policy.recognizedRevenue(tx)),100000);
 assert.equal(policy.transactionSemantics(tx).incomeDelta,0);
 const tx2={id:'unresolved-nosplit',type:'income',date:'2026-01-28',amount:110000,baseAmount:null,taxAmount:null,taxSnapshotStatus:'unresolved',transactionKind:'manual'};
 assert.equal(policy.recognizedRevenue(tx2),0);
});
check('Client bucket matches server: bookingId presence routes to room',()=>{
 const tx={id:'bk',type:'income',date:'2026-01-28',amount:110000,baseAmount:100000,taxAmount:10000,taxSnapshotStatus:'confirmed',transactionKind:'manual',categorySystemKey:'',bookingId:'B-123'};
 assert.equal(policy.transactionSemantics(tx).bucket,'room');
});
check('P&L invariants verified by behavior (see tests/pnl-model-behavior.mjs)', () => {
 // Converted from string matching to behavior: St=sum(buckets), ut=sum(expense),
 // ft=St-ut, sum(A)<=Ya, sum(Z)<=On are asserted with real data in
 // tests/pnl-model-behavior.mjs (5 scenarios).
 const report = read('assets/chunks/report.js');
 assert.ok(report.includes('Object.keys(A).map'));
 assert.ok(report.includes('Object.keys(Z).map'));
});
console.log(`${passed} passed; 0 failed`);
