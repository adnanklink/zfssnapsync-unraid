<?php
// Shared fake ZFS executable for isolated endpoint/daemon fixtures, never installed.
function source_fixture_zfs(int $count=7): void
{
    $snaps=[];for($i=1;$i<=$count;$i++)$snaps['s'.$i]=['guid'=>(string)(100+$i),'txg'=>(string)$i,'userrefs'=>'0','clones'=>'-',
        'properties'=>['org.zfs.snapsync:schedule'=>'abcdef123456','org.zfs.snapsync:source'=>'10','org.zfs.snapsync:occurrence'=>(string)$i]];
    $snaps['foreign']=['guid'=>'999999','txg'=>'999999','userrefs'=>'0','clones'=>'-','properties'=>[]];
    file_put_contents('/tmp/source-zfs.json',json_encode(['datasets'=>['tank/data'=>['guid'=>'10','token'=>'-','snapshots'=>$snaps],
        'backup/data'=>['guid'=>'20','token'=>'-','snapshots'=>['s'.$count=>$snaps['s'.$count]]]],'deleted'=>[]]));
    @mkdir('/usr/local/bin',0755,true);
    file_put_contents('/usr/local/bin/zfs', <<<'PY'
#!/usr/bin/python3
import sys,json,os
path='/tmp/source-zfs.json'
with open(path) as f: state=json.load(f)
a=sys.argv[1:]; op=a[0]
fields=a[a.index('-o')+1] if '-o' in a else ''
targets=a[a.index('--')+1:] if '--' in a else [a[-1]]
def find(name):
    parts=name.split('@',1); ds=state['datasets'][parts[0]]
    return ds['snapshots'][parts[1]] if len(parts)==2 else ds
try:
    if op=='list':
        name=targets[0]; ds=find(name)
        if fields=='name,guid' and '-t' in a and a[a.index('-t')+1]=='filesystem,volume':
            print(name+'\t'+ds['guid']);sys.exit(0)
        for snap,r in ds['snapshots'].items():
            values={'name':name+'@'+snap,'guid':r['guid'],'createtxg':r['txg'],'userrefs':r['userrefs'],'clones':r['clones']}
            print('\t'.join(values[field] for field in fields.split(',')))
    elif op=='get':
        properties=a[a.index('--')-1].split(',')
        names=targets[:]
        if '-r' in a:
            for name in targets:
                names += [name+'@'+snap for snap in find(name)['snapshots']]
        for name in names:
            row=find(name)
            for prop in properties:
                values={'guid':row['guid'],'createtxg':row.get('txg','0'),'userrefs':row.get('userrefs','0'),'clones':row.get('clones','-'),'receive_resume_token':row.get('token','-')}
                value=(row.get('properties') or {}).get(prop,values.get(prop,'-'))
                source='local' if prop in row.get('properties',{}) else '-'
                data={'name':name,'property':prop,'value':value,'source':source}
                print('\t'.join(data[field] for field in fields.split(',')))
    elif op=='destroy':
        name=targets[0]; row=find(name)
        assert row['userrefs']=='0' and row['clones']=='-'
        dataset,snapshot=name.split('@',1)
        del state['datasets'][dataset]['snapshots'][snapshot]
        assert name not in state['deleted']
        state['deleted'].append(name)
        with open(path+'.pending','w') as f:json.dump(state,f)
        os.replace(path+'.pending',path)
    else: raise ValueError('unexpected command '+str(a))
except (KeyError,ValueError,AssertionError) as error:
    print(str(error),file=sys.stderr);sys.exit(1)
PY);
    chmod('/usr/local/bin/zfs',0755);
}
