
import React from 'react';
import { useParams } from 'react-router-dom';
import { Card } from '@/components/ui/card';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Separator } from '@/components/ui/separator';
import { DeviceInfo } from '@/components/DeviceInfo';
import { DeviceParameters } from '@/components/DeviceParameters';
import { DeviceActions } from '@/components/DeviceActions';
import { DeviceStats } from '@/components/DeviceStats';
import { ConnectedHosts } from '@/components/ConnectedHosts';

export default function DevicePage() {
  const { id } = useParams<{ id: string }>();
  const deviceId = id || '';
  
  return (
    <div className="container mx-auto py-6">
      <h1 className="text-2xl font-bold mb-6">Device Details</h1>
      
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div className="lg:col-span-2">
          <Card className="p-6">
            <DeviceInfo deviceId={deviceId} />
            <Separator className="my-6" />
            
            <Tabs defaultValue="parameters" className="w-full">
              <TabsList className="mb-4">
                <TabsTrigger value="parameters">Parameters</TabsTrigger>
                <TabsTrigger value="stats">Stats</TabsTrigger>
                <TabsTrigger value="clients">Connected Clients</TabsTrigger>
              </TabsList>
              
              <TabsContent value="parameters">
                <DeviceParameters deviceId={deviceId} />
              </TabsContent>
              
              <TabsContent value="stats">
                <DeviceStats deviceId={deviceId} />
              </TabsContent>
              
              <TabsContent value="clients">
                <ConnectedHosts deviceId={deviceId} />
              </TabsContent>
            </Tabs>
          </Card>
        </div>
        
        <div>
          <Card className="p-6">
            <DeviceActions deviceId={deviceId} />
          </Card>
        </div>
      </div>
    </div>
  );
}
