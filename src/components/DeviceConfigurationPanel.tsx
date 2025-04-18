import React, { useState, useEffect } from 'react';
import { toast } from "sonner";
import {
  Tabs,
  TabsContent,
  TabsList,
  TabsTrigger,
} from "@/components/ui/tabs";
import { Wifi, Globe, PowerOff, Server } from "lucide-react";

// Fix import statements to use default imports
import WifiConfiguration from './deviceConfiguration/WifiConfiguration';
import { WanConfiguration } from './deviceConfiguration/WanConfiguration';
import RebootConfiguration from './deviceConfiguration/RebootConfiguration';
import TR069Management from './deviceConfiguration/TR069Management';
import ConnectionRequestSettings from './deviceConfiguration/ConnectionRequestSettings';

interface DeviceConfigurationPanelProps {
  deviceId: string;
}

export const DeviceConfigurationPanel: React.FC<DeviceConfigurationPanelProps> = ({ deviceId }) => {
  const [loading, setLoading] = useState(false);
  const [connectionRequest, setConnectionRequest] = useState<any>(null);
  const [tr069SessionId, setTr069SessionId] = useState<string | null>(null);
  
  useEffect(() => {
    const fetchDeviceSettings = async () => {
      try {
        setLoading(true);
        const formData = new FormData();
        formData.append('device_id', deviceId);
        formData.append('action', 'get_settings');
        
        const response = await fetch('/backend/api/device_configure.php', {
          method: 'POST',
          body: formData
        });
        
        const result = await response.json();
        
        if (result.success && result.settings) {
          // Settings will be handled in their respective components
          if (result.connection_request) {
            setConnectionRequest(result.connection_request);
          }
          
          if (result.tr069_session_id) {
            setTr069SessionId(result.tr069_session_id);
          }
        } else {
          toast.error("Failed to load device settings");
        }
      } catch (error) {
        toast.error("Error loading device settings");
      } finally {
        setLoading(false);
      }
    };
    
    fetchDeviceSettings();
  }, [deviceId]);

  const handleConnectionRequestSuccess = (request: any) => {
    setConnectionRequest(request);
    if (request.tr069_session_id) {
      setTr069SessionId(request.tr069_session_id);
    }
  };

  if (loading) {
    return (
      <div className="space-y-6 p-4 border rounded-lg bg-white shadow-sm flex items-center justify-center h-64">
        <div className="text-center">
          <div className="animate-spin h-8 w-8 border-4 border-primary border-t-transparent rounded-full mx-auto mb-4"></div>
          <p className="text-gray-500">Loading device settings...</p>
        </div>
      </div>
    );
  }

  return (
    <div className="space-y-6 p-4 border rounded-lg bg-white shadow-sm">
      <Tabs defaultValue="wifi" className="w-full">
        <TabsList className="w-full">
          <TabsTrigger value="wifi" className="flex-1"><Wifi className="h-4 w-4 mr-2" /> WiFi</TabsTrigger>
          <TabsTrigger value="wan" className="flex-1"><Globe className="h-4 w-4 mr-2" /> WAN</TabsTrigger>
          <TabsTrigger value="tr069" className="flex-1"><Server className="h-4 w-4 mr-2" /> TR-069</TabsTrigger>
          <TabsTrigger value="control" className="flex-1"><PowerOff className="h-4 w-4 mr-2" /> Control</TabsTrigger>
        </TabsList>
        
        <TabsContent value="wifi" className="pt-4">
          <WifiConfiguration 
            deviceId={deviceId} 
            onSuccess={handleConnectionRequestSuccess}
          />
        </TabsContent>
        
        <TabsContent value="wan" className="pt-4">
          <WanConfiguration deviceId={deviceId} />
        </TabsContent>
        
        <TabsContent value="tr069" className="pt-4">
          <TR069Management 
            deviceId={deviceId}
            tr069SessionId={tr069SessionId}
          />
        </TabsContent>
        
        <TabsContent value="control" className="pt-4">
          <div className="space-y-6">
            <ConnectionRequestSettings deviceId={deviceId} />
            
            <div className="mt-6">
              <RebootConfiguration 
                deviceId={deviceId}
                onSuccess={handleConnectionRequestSuccess}
              />
            </div>
          </div>
        </TabsContent>
      </Tabs>
    </div>
  );
};

export default DeviceConfigurationPanel;
