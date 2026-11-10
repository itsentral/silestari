const {test}=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const source=fs.readFileSync('resources/legacy/iso.html','utf8'),c={};vm.createContext(c);
for(const name of ['permitStepFields','permitValidate'])vm.runInContext(source.split('\n').find(l=>l.startsWith('function '+name+'(')),c);
const steps=[{step:'Kerja',hazard:'Bahaya',impact:'Dampak',control:'Kontrol',pic:'PIC'}];
const permit={status:'Selesai',start:'2026-10-30T08:00',end:'2026-10-30T17:00',approvedAt:'2026-10-30T07:30',checks:'Siap',reviewer:'Reviewer',approver:'Approver',closeCheck:'Selesai aman',closedBy:'PIC',closedAt:'2026-10-30T16:30'};
test('completed permit cannot close before work starts',()=>assert.match(c.permitValidate({...permit,closedAt:'2026-10-29T16:00'},steps),/penutupan.*mulai/i));
test('completed permit cannot close before approval',()=>assert.match(c.permitValidate({...permit,approvedAt:'2026-10-30T10:00',closedAt:'2026-10-30T09:00'},steps),/penutupan.*persetujuan/i));
test('valid closure and closure after expiry remain allowed',()=>{assert.equal(c.permitValidate(permit,steps),'');assert.equal(c.permitValidate({...permit,closedAt:'2026-10-30T18:00'},steps),'')});
test('draft does not require approval or closure',()=>assert.equal(c.permitValidate({status:'Draft',start:permit.start,end:permit.end},[]),''));
