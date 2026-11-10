const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const source=fs.readFileSync('resources/legacy/audit.html','utf8');
const save=source.split('\n').find(s=>s.startsWith('function save(){'));
function client(failure, sync=true){
 let acknowledged={reports:[{id:'r',auditorEvaluation:[]}],records:[{id:'kept'}]};
 const elements=new Map(),messages=[];
 const c={db:{reports:[{id:'r',auditorEvaluation:[]}],records:[]},KEY:'test',storageError:'',messages,
  localStorage:{getItem:()=>JSON.stringify(acknowledged)},
  auditScoped:x=>x,
  auditScopedSave(){if(failure)throw Error('Sesi berakhir.');acknowledged=JSON.parse(JSON.stringify(c.db));return c.db;},
  SIAuditSync:{sync:()=>sync},toast:m=>messages.push(m),render(){},
  $(key){if(!elements.has(key))elements.set(key,{close(){this.closed=true;}});return elements.get(key);},
  location:{hash:'#original'},clone:x=>JSON.parse(JSON.stringify(x)),validate(){},seed:{source:{},master:{}},window:{confirm:()=>true}};
 vm.createContext(c);vm.runInContext(save,c);
 return {c,ack:()=>acknowledged};
}
test('failed audit save restores acknowledged records and reports failure',()=>{
 const {c}=client(true);assert.equal(c.save(),false);assert.equal(c.db.records[0].id,'kept');assert.equal(c.storageError,'Sesi berakhir.');
});
test('successful audit save reports success after acknowledgment',()=>{
 const {c,ack}=client(false);assert.equal(c.save(),true);assert.equal(ack().records.length,0);assert.equal(c.storageError,'');
});
test('partial synchronization failure keeps committed audit and reports pending synchronization',()=>{
 const {c,ack}=client(false,false);assert.equal(c.save(),false);assert.equal(c.db.records.length,0);assert.equal(ack().records.length,0);assert.match(c.storageError,/Audit tersimpan.*sinkronisasi/);
});
function deleteHandler(c){
 c.pendingDelete={type:'records',id:'kept'};c.current=()=>({type:'records',id:'kept'});
 c.deletionPlan=()=>({candidate:{reports:[],records:[]},type:'records',id:'kept',target:{},count:1,adjustments:[]});
 c.routeTrail=['original'];c.routeExists=()=>false;
 c.deletionDialog={addEventListener(type,callback){c.submit=callback;},close(){this.closed=true;}};
 const start=source.indexOf("deletionDialog.addEventListener('submit'");
 vm.runInContext(source.slice(start,source.indexOf('\n});',start)+4),c);
 c.submit({preventDefault(){}});
}
test('failed audit deletion keeps dialog and route, without success toast',()=>{
 const {c}=client(true);deleteHandler(c);assert.equal(c.deletionDialog.closed,undefined);assert.equal(c.location.hash,'#original');assert.equal(c.routeTrail.length,1);assert.deepEqual(c.messages,['Sesi berakhir.']);
});
test('successful audit deletion closes dialog and confirms deletion',()=>{
 const {c}=client(false);deleteHandler(c);assert.equal(c.deletionDialog.closed,true);assert.equal(c.location.hash,'#records');assert.match(c.messages.at(-1),/1 record dihapus/);
});
for(const failure of [true,false])test(`audit import ${failure?'failure never claims success':'success acknowledges replacement'}`,async()=>{
 const {c,ack}=client(failure);
 vm.runInContext(source.split('\n').find(s=>s.startsWith("$('#import').onchange=")),c);
 const target={files:[{size:20,text:async()=>JSON.stringify({reports:[],records:[{id:'imported'}]})}],value:'file'};
 await c.$('#import').onchange({target});
 assert.equal(target.value,'');
 if(failure){assert.equal(ack().records[0].id,'kept');assert.match(c.messages.at(-1),/Impor gagal: Sesi berakhir/);assert.ok(!c.messages.includes('Data berhasil diimpor.'));}
 else{assert.equal(ack().records[0].id,'imported');assert.equal(c.messages.at(-1),'Data berhasil diimpor.');}
});
for(const failure of [true,false])for(const evaluation of [true,false])test(`${evaluation?'auditor evaluation':'audit record'} editor ${failure?'stays open after failed save':'confirms acknowledged save'}`,()=>{
 const {c}=client(failure);
 c.db.programs=[{id:'p',year:'2026',title:'Before'}];
 c.editContext=evaluation?{type:'evaluation',reportId:'r',record:{}}:{type:'programs',record:{id:'p',year:'2026',title:'Before'}};
 c.get=(type,id)=>c.db[type].find(row=>row.id===id);
 c.FormData=class{constructor(){return evaluation?[['auditor','Reviewer']]:[['title','After'],['year','2026']];}};
 c.$('#editor').addEventListener=(type,callback)=>{c.submitEditor=callback;};
 const start=source.indexOf("$('#editor').addEventListener('submit'");
 vm.runInContext(source.slice(start,source.indexOf('\n});',start)+4),c);
 c.submitEditor({preventDefault(){},target:{}});
 if(failure){assert.equal(c.$('#dialog').closed,undefined);assert.equal(c.location.hash,'#original');assert.deepEqual(c.messages,['Sesi berakhir.']);}
 else{assert.equal(c.$('#dialog').closed,true);assert.match(c.messages.at(-1),/tersimpan/);}
});
