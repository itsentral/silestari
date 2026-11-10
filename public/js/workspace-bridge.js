(()=>{
'use strict';
document.addEventListener('click',event=>{const target=event.target.closest('[onclick]');if(target&&/\bgo\(['"]policy['"]\)/.test(target.getAttribute('onclick')||'')){event.preventDefault();event.stopImmediatePropagation();location.assign('/policy');}},true);
document.addEventListener('click',event=>{const target=event.target.closest('[onclick]');if(target&&/\bgo\(['"]communication['"]\)/.test(target.getAttribute('onclick')||'')){event.preventDefault();event.stopImmediatePropagation();location.assign('/communication');}},true);
document.addEventListener('click',event=>{const target=event.target.closest('[onclick]');if(target&&/\bgo\(['"]csms['"]\)/.test(target.getAttribute('onclick')||'')){event.preventDefault();event.stopImmediatePropagation();location.assign('/csms');}},true);
const boot=window.SILESTARI_SERVER,keys={'iso-k3l-v1':'iso','ruang-risiko-asli-offline-v1':'risk','audit-internal-template-v1':'audit'},memory=new Map();
const base=Storage.prototype,originalGet=base.getItem,originalSet=base.setItem,originalRemove=base.removeItem,originalClear=base.clear;
const escape=x=>String(x??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const aliases={trainingMatrix:'training',trainingNeeds:'training',trainingProgram:'training',trainingEvaluation:'training',wastePermits:'waste',wasteTypes:'waste',wasteLog:'waste',wasteBalance:'waste',contextIssueDocs:'issues',contextPartyDocs:'parties',emergencySchedules:'emergency',emergencyStructure:'emergency',emergencyDuties:'emergency',emergencyReports:'emergency',emergencyEquipment:'emergency',emergencyEquipmentHub:'emergency',emergencyEquipmentSchedule:'emergency',emergencyEquipmentChecklist:'emergency',regulations:'legal',regulationUpdates:'legal',regulationNotes:'legal',reviewSchedules:'managementReview',reviewMaterials:'managementReview',reviewOutputs:'managementReview'};
const groups={context:['parties','issues'],planning:['risk','actions','legal','targets','moc'],execution:['chemicals','waste','solidWaste','emergency','communication','documents','training','csms','workPermits'],monitoring:['performance','audit','managementReview'],performance:['monitor','analysis'],improvementHub:['nonconformity','incidents','kaizen']};
function can(page){if(['scope','opportunity'].includes(page))return false;if(page==='dashboard')return true;if(boot.user.role==='admin')return true;if(['accounts','data','integrations','templates','operations','improvement'].includes(page))return false;if(groups[page])return groups[page].some(can);return !!boot.user.department&&boot.user.allowed.includes(aliases[page]||page);}
function workspaceParts(text){
const parts=[];let start=0,bytes=0;
for(let i=0;i<text.length;i++){
const code=text.charCodeAt(i),pair=code>=0xD800&&code<=0xDBFF&&i+1<text.length&&text.charCodeAt(i+1)>=0xDC00&&text.charCodeAt(i+1)<=0xDFFF;
const size=code<128?(code===34||code===92?2:code<32?6:1):code<2048?2:pair?4:3;
if(bytes+size>900000){parts.push(text.slice(start,i));start=i;bytes=0;}
bytes+=size;if(pair)i++;
}
parts.push(text.slice(start));return parts;
}
function request(url,body){
const serialized=JSON.stringify(body);
if(serialized.length>500000&&!url.endsWith('/chunks')){
const upload=crypto.randomUUID(),parts=workspaceParts(serialized);let result;
for(let index=0;index<parts.length;index++)result=request(url+'/chunks',{upload,index,total:parts.length,data:parts[index]});
return result;
}
const xhr=new XMLHttpRequest();xhr.open('POST',url,false);
xhr.setRequestHeader('Content-Type','application/json');xhr.setRequestHeader('Accept','application/json');xhr.setRequestHeader('X-CSRF-TOKEN',boot.csrf);
try{xhr.send(serialized);}catch{throw Error('Tidak dapat menghubungi server. Periksa koneksi lalu coba kembali.');}
if(xhr.status<200||xhr.status>=300){
let message;
if(xhr.status===409)message='Data sudah diubah pengguna lain. Muat ulang halaman sebelum menyimpan.';
else if(xhr.status===401||xhr.status===419)message='Sesi berakhir. Masuk kembali sebelum menyimpan.';
else if(xhr.status===403)message='Anda tidak memiliki hak untuk mengubah data ini.';
else if(xhr.status===413)message='Ukuran data melebihi batas unggahan server. Kurangi ukuran lampiran lalu coba kembali.';
else if(xhr.status===429)message='Terlalu banyak permintaan atau unggahan sementara. Tunggu beberapa saat lalu coba kembali.';
else if(xhr.status===422){
message='Data tidak lolos validasi. Periksa isian sebelum menyimpan.';
try{const errors=JSON.parse(xhr.responseText).errors;if(errors&&typeof errors==='object'){const details=Object.values(errors).flat().filter(x=>typeof x==='string');if(details.length)message=details.join(' ');}}catch{}
}
else if(xhr.status===0)message='Tidak dapat menghubungi server. Periksa koneksi lalu coba kembali.';
else message='Server gagal menyimpan data. Pastikan MySQL di DBngin aktif, lalu coba kembali. Jika tetap gagal, hubungi admin.';
throw Error(message);
}
try{return JSON.parse(xhr.responseText);}catch{throw Error('Respons server tidak dapat dibaca. Muat ulang halaman untuk memastikan status penyimpanan.');}
}
// Compatibility for the existing synchronous forms: acknowledge only after MySQL commits.
// Operational values are held in memory, never copied to browser localStorage.
base.getItem=function(key){if(this===localStorage&&keys[key])return JSON.stringify(boot.workspaces[keys[key]].payload);if(this===localStorage&&key==='iso-accounts-v1')return '[]';return memory.has(key)?memory.get(key):originalGet.call(this,key);};
base.setItem=function(key,value){if(this===localStorage&&keys[key]){const source=keys[key],payload=JSON.parse(String(value)),current=boot.workspaces[source];if(JSON.stringify(current.payload)===JSON.stringify(payload))return;const next=request('/workspace/'+source,{revision:current.revision,payload});if(!next||!Number.isInteger(next.revision)||next.revision<1||!next.payload||typeof next.payload!=='object'||Array.isArray(next.payload))throw Error('Respons server tidak memastikan data tersimpan. Muat ulang halaman sebelum melanjutkan.');boot.workspaces[source]=next;return;}if(this===localStorage&&/iso-(accounts|session)/.test(key))throw Error('Akun dikelola oleh server.');memory.set(key,String(value));};
base.removeItem=function(key){if(keys[key])throw Error('Gunakan pemulihan data melalui menu Cadangan.');memory.delete(key);originalRemove.call(this,key);};
base.clear=function(){throw Error('Pembersihan data server tidak diizinkan melalui browser.');};
function logout(){const f=document.createElement('form');f.method='POST';f.action='/logout';const i=document.createElement('input');i.name='_token';i.value=boot.csrf;f.append(i);document.body.append(f);f.submit();}
window.ISOAuth={user:()=>boot.user,can,escape,logout,accountView:()=>boot.accountsHTML||'<div class="card"><h1>Akun & Hak Akses</h1><p>Akun dan hak akses dikelola di server Laravel.</p><a href="/users">Kelola akun & hak akses →</a></div>',edit:()=>location.assign('/users'),changePassword:()=>location.assign('/password'),recoverySettings:()=>alert('Hubungi admin untuk mengatur ulang password akun di server.'),backupAccounts:()=>location.assign('/workspace-backup')};
window.addEventListener('error',e=>{if(/Data belum tersimpan|Sesi berakhir|Data sudah diubah|tidak memiliki hak/.test(e.message))alert(e.message);});
document.addEventListener('DOMContentLoaded',()=>{for(const el of document.querySelectorAll('a[href]')){const href=el.getAttribute('href');if(/ISO-K3L|index\.html/.test(href||''))el.href='/dashboard';}const note=document.createElement('div');note.id='server-storage-indicator';note.textContent='Laravel · MySQL';note.style.cssText='position:fixed;bottom:8px;left:8px;z-index:10000;background:#005e50;color:white;border-radius:5px;padding:5px 10px;font:11px Arial;pointer-events:none';document.body.append(note);});
})();
