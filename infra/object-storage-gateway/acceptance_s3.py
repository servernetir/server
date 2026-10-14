import argparse, json, os, sys, urllib.request
import boto3
from botocore.config import Config
from botocore.exceptions import ClientError

def client(c):
    return boto3.client('s3', endpoint_url=c['endpoint'], aws_access_key_id=c['access_key'], aws_secret_access_key=c['secret_access_key'], region_name='us-east-1', config=Config(signature_version='s3v4', s3={'addressing_style':'path'}))

def denied(fn):
    try: fn(); return False
    except ClientError as e: return int(e.response.get('ResponseMetadata',{}).get('HTTPStatusCode',0)) in (401,403)

def basic(p):
    a,b=p['a'],p['b']; c=client(a); out={}
    c.put_object(Bucket=a['bucket'],Key='basic.txt',Body=b'servernet-acceptance')
    out['put_get_head_list']=(c.get_object(Bucket=a['bucket'],Key='basic.txt')['Body'].read()==b'servernet-acceptance' and c.head_object(Bucket=a['bucket'],Key='basic.txt')['ContentLength']==20 and any(x['Key']=='basic.txt' for x in c.list_objects_v2(Bucket=a['bucket']).get('Contents',[])))
    url=c.generate_presigned_url('get_object',Params={'Bucket':a['bucket'],'Key':'basic.txt'},ExpiresIn=120)
    out['presigned']=urllib.request.urlopen(url,timeout=30).read()==b'servernet-acceptance'
    upload=c.create_multipart_upload(Bucket=a['bucket'],Key='multipart.bin'); uid=upload['UploadId']; parts=[]
    for n,body in ((1,b'a'*(5*1024*1024)),(2,b'b'*(5*1024*1024))):
        x=c.upload_part(Bucket=a['bucket'],Key='multipart.bin',UploadId=uid,PartNumber=n,Body=body); parts.append({'ETag':x['ETag'],'PartNumber':n})
    c.complete_multipart_upload(Bucket=a['bucket'],Key='multipart.bin',UploadId=uid,MultipartUpload={'Parts':parts})
    out['multipart_complete']=c.head_object(Bucket=a['bucket'],Key='multipart.bin')['ContentLength']==10*1024*1024
    abort=c.create_multipart_upload(Bucket=a['bucket'],Key='abort.bin'); c.abort_multipart_upload(Bucket=a['bucket'],Key='abort.bin',UploadId=abort['UploadId']); out['multipart_abort']=True
    out['create_bucket_denied']=denied(lambda:c.create_bucket(Bucket='sn-forbidden-acceptance'))
    out['tenant_isolation']=denied(lambda:c.list_objects_v2(Bucket=b['bucket']))
    c.delete_object(Bucket=a['bucket'],Key='basic.txt'); c.delete_object(Bucket=a['bucket'],Key='multipart.bin')
    out['delete']=not c.list_objects_v2(Bucket=a['bucket']).get('Contents')
    return out

def auth(p,which,expect):
    ok=True
    try: client(p[which]).list_objects_v2(Bucket=p[which]['bucket'])
    except ClientError: ok=False
    return {'auth_expected':ok==expect,'actual':ok}

ap=argparse.ArgumentParser(); ap.add_argument('payload'); ap.add_argument('mode'); ap.add_argument('--which',default='a'); ap.add_argument('--expect',choices=['true','false'],default='true'); z=ap.parse_args(); p=json.load(open(z.payload))
out=basic(p) if z.mode=='basic' else auth(p,z.which,z.expect=='true')
print(json.dumps(out,separators=(',',':')))
sys.exit(0 if all(v is True for k,v in out.items() if k!='actual') else 1)
