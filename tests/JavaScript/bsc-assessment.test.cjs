const {test}=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const source=fs.readFileSync('resources/legacy/iso.html','utf8');
const c={bscMonths:Array.from({length:12},(_,i)=>String(i))};vm.createContext(c);
for(const name of ['dashNumber','bscValues','bscValue','bscAssessment','targetResult'])vm.runInContext(source.split('\n').find(s=>s.startsWith('function '+name+'(')),c);
for(const [name,record,expected] of [
 ['zero cases with a numeric target',{title:'Customer Claim',target:'0',targetText:'0 Case',actual:'1'},'Belum tercapai'],
 ['maximum weeks with a numeric target',{title:'Ketepatan waktu',target:'3',targetText:'Max 3 Minggu',actual:'4'},'Belum tercapai'],
 ['loss reduction with a numeric target',{title:'Menurukan Loss Repeat Order',target:'0.2',targetText:'0.2',actual:'0.1'},'Tercapai'],
 ['explicit user direction takes priority',{title:'Custom indicator',targetText:'0 Case',actual:'1',direction:'Semakin tinggi semakin baik'},'Tercapai'],
 ['numeric zero is a real measurement',{target:'0',actual:0,direction:'Semakin rendah semakin baik'},'Tercapai'],
 ['whitespace is not a measurement',{target:'0',actual:'   ',direction:'Semakin rendah semakin baik'},'Belum dinilai'],
 ['descriptive monthly entries remain unassessed',{target:'10',monthly:'["On Progress"]'},'Belum dinilai'],
 ['higher is better when configured',{target:'10',actual:'12',direction:'Semakin tinggi semakin baik'},'Tercapai']
])test('BSC assesses '+name+' correctly',()=>assert.equal(c.targetResult(record),expected));
