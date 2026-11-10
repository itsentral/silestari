const {test}=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const source=fs.readFileSync('resources/legacy/iso.html','utf8');
const c={window:{SILESTARI_SERVER:{nativeCounts:{communication:1,documents:2,csms:0}}}};
vm.createContext(c);
for(const name of ['nativeModuleCount','bscDisplay'])vm.runInContext(source.split('\n').find(s=>s.startsWith('function '+name+'(')),c);
test('module labels use native data and fall back when no count is provided',()=>{
 assert.equal(c.nativeModuleCount('communication'),'1 MATRIKS');assert.equal(c.nativeModuleCount('documents'),'2 DOKUMEN');assert.equal(c.nativeModuleCount('csms'),'0 PENYEDIA');assert.equal(c.nativeModuleCount('targets'),null);
});
test('BSC display rounds floating artifacts while preserving empty and descriptive values',()=>{
 assert.equal(c.bscDisplay('0.7600000000000001'),'0,76');assert.equal(c.bscDisplay('0.16666666666666666'),'0,1667');assert.equal(c.bscDisplay('1217024583'),'1.217.024.583');assert.equal(c.bscDisplay('0'),'0');assert.equal(c.bscDisplay(''),'');assert.equal(c.bscDisplay('On Progress'),'On Progress');assert.equal(c.bscDisplay('< 30%'),'< 30%');
});
