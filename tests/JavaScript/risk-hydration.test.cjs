const {test}=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const source=fs.readFileSync('resources/legacy/risk.html','utf8');
function context(){const c={identifyMeta(){}};vm.createContext(c);for(const name of ['upgradeReviews','validDepartments','validDocuments','validSettings','validateDb'])vm.runInContext(source.split('\n').find(s=>s.startsWith('function '+name+'(')),c);return c;}
function state(){return {schema:1,revision:1,years:[2026],departments:[{name:'HSE',units:[]}],records:[],documents:[],archives:[],audit:[],settings:{company:'PT Test',thresholds:[2,4,8,12]}};}
test('HIRADC accepts an empty document map serialized by PHP as an array',()=>{
 const c=context(),data=state();assert.equal(c.validateDb(data),false);c.upgradeReviews(data);assert.equal(c.validateDb(data),true);assert.equal(Array.isArray(data.documents),false);assert.equal(data.revision,1);
});
test('department hydration accepts an empty entry context withheld by server scope',()=>{
 const c=context(),data=state();data.settings.entryContext=[];c.upgradeReviews(data);assert.equal(c.validateDb(data),true);assert.equal(data.settings.entryContext,undefined);
});
test('empty maps in annual archives are normalized as well',()=>{
 const c=context(),data=state();data.archives=[{id:'archive',year:2026,at:'2026-01-01',note:'Archived',departments:data.departments,settings:{...data.settings,entryContext:[]},documents:[],records:[]}];c.upgradeReviews(data);assert.equal(c.validateDb(data),true);
});
test('normalization preserves populated document maps and entry context',()=>{
 const c=context(),data=state();data.documents={'2026::HSE':{year:2026,dept:'HSE',number:'HSE-01',revision:'1',date:'',revisedBy:'',revisionItem:'',pic:'',createdBy:'',approvedBy:'',status:'Draft'}};data.settings.entryContext={dept:'HSE',unit:'Unit',activity:'Work'};const before=JSON.stringify(data);c.upgradeReviews(data);assert.equal(JSON.stringify(data),before);assert.equal(c.validateDb(data),true);
});
test('malformed nonempty document arrays remain rejected instead of being discarded',()=>{
 const c=context(),data=state();data.documents=[{id:'broken'}];const before=JSON.stringify(data.documents);c.upgradeReviews(data);assert.equal(c.validateDb(data),false);assert.equal(JSON.stringify(data.documents),before);
});
