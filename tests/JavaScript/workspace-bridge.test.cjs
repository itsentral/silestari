const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const bridge = fs.readFileSync('public/js/workspace-bridge.js', 'utf8');
const isoSave = fs.readFileSync('resources/legacy/iso.html', 'utf8').split('\n').find(line => line.startsWith('function save(){'));
function client(respond) {
  class Storage { getItem(){return null;} setItem(){} removeItem(){} clear(){} }
  const initial = {revision: 1, payload: {records: {targets: [{id:'kept'}]}}};
  const boot = {csrf:'test', user:{role:'admin'}, workspaces:{iso:initial}};
  const calls = [];
  class XHR {
    open(method,url){this.url=url;}
    setRequestHeader(){}
    send(body){calls.push({url:this.url,body:JSON.parse(body)});Object.assign(this,respond(calls.length));}
  }
  const context = {Storage, localStorage:new Storage(), XMLHttpRequest:XHR, crypto:{randomUUID:()=> '13e9adf5-e4bb-4a7d-8e71-542230ea5300'}, document:{addEventListener(){}}, window:{SILESTARI_SERVER:boot,addEventListener(){}}};
  vm.createContext(context);vm.runInContext(bridge,context);
  return {boot,initial,calls,context,save:payload=>context.localStorage.setItem('iso-k3l-v1',JSON.stringify(payload))};
}
for (const [status,message] of [[0,/menghubungi/],[401,/Sesi berakhir/],[419,/Sesi berakhir/],[403,/hak/],[409,/diubah pengguna lain/],[413,/Ukuran data/],[422,/validasi/],[429,/Terlalu banyak/],[500,/Server gagal/],[503,/Server gagal/]]) {
  test(`failed save HTTP ${status} preserves acknowledged data and gives an actionable message`,()=>{
    const c=client(()=>({status,responseText:'<private server diagnostics>'}));
    assert.throws(()=>c.save({records:{targets:[]}}),message);
    assert.strictEqual(c.boot.workspaces.iso,c.initial);
  });
}
test('validation errors are shown without exposing server debug messages',()=>{
  const c=client(()=>({status:422,responseText:JSON.stringify({message:'private trace',errors:{title:['Judul wajib diisi.']}})}));
  assert.throws(()=>c.save({records:{targets:[]}}),/^Error: Judul wajib diisi\.$/);
});
test('connection exceptions preserve acknowledged data',()=>{
  const c=client(()=>{throw Error('NetworkError');});
  assert.throws(()=>c.save({records:{targets:[]}}),/menghubungi server/);
  assert.strictEqual(c.boot.workspaces.iso,c.initial);
});
test('invalid successful response asks for reload and preserves acknowledged data',()=>{
  const c=client(()=>({status:200,responseText:'<html>login</html>'}));
  assert.throws(()=>c.save({records:{targets:[]}}),/Respons server/);
  assert.strictEqual(c.boot.workspaces.iso,c.initial);
});
test('a committed deletion advances the acknowledged revision',()=>{
  const saved={revision:2,payload:{records:{targets:[]}}};
  const c=client(()=>({status:200,responseText:JSON.stringify(saved)}));c.save(saved.payload);
  assert.deepEqual(JSON.parse(JSON.stringify(c.boot.workspaces.iso)),saved);
});
test('failed final chunk does not acknowledge a large save',()=>{
  const c=client(index=>({status:index===4?500:200,responseText:JSON.stringify({received:index-1})}));
  assert.throws(()=>c.save({attachment:'x'.repeat(2800000)}),/Server gagal/);
  assert.equal(c.calls.length,4);assert.equal(c.calls[3].url,'/workspace/iso/chunks');
  assert.strictEqual(c.boot.workspaces.iso,c.initial);
});
test('ISO save preserves specific errors from the bridge instead of replacing them',()=>{
  const context={siScopedSave(){throw Error('Sesi berakhir. Masuk kembali sebelum menyimpan.');},toast(message){context.message=message;}};
  vm.createContext(context);vm.runInContext(isoSave,context);
  assert.equal(context.save(),false);assert.equal(context.message,'Sesi berakhir. Masuk kembali sebelum menyimpan.');
});
for(const response of [{},{received:0},{revision:2,payload:[]},{revision:2},{revision:0,payload:{}}])test(`incomplete acknowledgment ${JSON.stringify(response)} cannot replace workspace`,()=>{
 const c=client(()=>({status:200,responseText:JSON.stringify(response)}));
 assert.throws(()=>c.save({records:{targets:[]}}),/Respons server tidak memastikan/);
 assert.strictEqual(c.boot.workspaces.iso,c.initial);
});

test('large saves reduce requests while preserving exact Unicode and escaped contents',()=>{
 const payload={text:('data\\"😀環境\n').repeat(150000)};
 const c=client(index=>({status:200,responseText:JSON.stringify({revision:2,payload})}));c.save(payload);
 const joined=c.calls.map(x=>x.body.data).join('');assert.deepEqual(JSON.parse(joined).payload,payload);
 assert.ok(c.calls.length<Math.ceil(JSON.stringify({revision:1,payload}).length/130000));
 for(const call of c.calls){assert.ok(Buffer.byteLength(JSON.stringify(call.body))<1000000);assert.ok(Array.from(call.body.data).length<=900000);}
});
test('a 5.4 MB ASCII workspace uses seven requests instead of forty two',()=>{
 const payload={text:'x'.repeat(5435000)};const c=client(()=>({status:200,responseText:JSON.stringify({revision:2,payload})}));c.save(payload);assert.equal(c.calls.length,7);
});
