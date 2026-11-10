const {test}=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const source=fs.readFileSync('resources/legacy/iso.html','utf8');
function context(denied=[]){
 const data={reviewSchedules:[1,2],reviewMaterials:[1],reviewOutputs:[1],managementReview:[1],legal:[1],regulations:[1,2],regulationUpdates:[1],regulationNotes:[1],monitor:[1,2],analysis:[3],waste:[1,2,3],wastePermits:[1],wasteTypes:[1],emergencyStructure:[1],emergencyEquipment:[1],emergencyEquipmentSchedule:[1],trainingMatrix:[1],trainingNeeds:[1,2],trainingProgram:[1],trainingEvaluation:[]};
 const c={ISOAuth:{can:k=>!denied.includes(k)},records:k=>data[k==='wasteLog'?'waste':k]||[]};vm.createContext(c);
 for(const prefix of ['const groupPages=','const names=','function moduleRecordCount('])vm.runInContext(source.split('\n').find(l=>l.startsWith(prefix)),c);
 return c;
}
test('training group has a page title',()=>assert.equal(vm.runInContext('names.training',context()),'Pelatihan dan Pengembangan SDM'));
test('nested group counts include records in all visible children',()=>{
 const c=context();assert.equal(c.moduleRecordCount('performance'),3);assert.equal(c.moduleRecordCount('monitoring'),8);assert.equal(c.moduleRecordCount('emergency'),3);assert.equal(c.moduleRecordCount('training'),4);assert.equal(c.moduleRecordCount('waste'),5);
});
test('group counts exclude inaccessible children',()=>{const c=context(['analysis','emergencyEquipmentSchedule']);assert.equal(c.moduleRecordCount('performance'),2);assert.equal(c.moduleRecordCount('emergency'),2)});
test('backup description identifies separate server modules',()=>{assert.ok(!source.includes('<h2>Cadangan seluruh workspace</h2>'));assert.ok(source.includes('Cadangan ini tidak mencakup workspace HIRADC dan Audit'));});

test('tabbed management review and compliance include all their registers',()=>{const c=context();assert.equal(c.moduleRecordCount('managementReview'),5);assert.equal(c.moduleRecordCount('legal'),5);assert.equal(context(['reviewMaterials']).moduleRecordCount('managementReview'),4)});
