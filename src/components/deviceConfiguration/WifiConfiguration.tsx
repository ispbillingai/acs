
import React, { useState, useEffect } from 'react';
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { toast } from "sonner";
import { Wifi, AlertTriangle, ShieldCheck } from "lucide-react";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import {
  Alert,
  AlertDescription,
  AlertTitle,
} from "@/components/ui/alert";

interface WifiConfigurationProps {
  deviceId: string;
  onSuccess?: (connectionRequest: any) => void;
}

const WifiConfiguration: React.FC<WifiConfigurationProps> = ({ 
  deviceId,
  onSuccess
}) => {
  const [ssid, setSsid] = useState('');
  const [password, setPassword] = useState('');
  const [security, setSecurity] = useState('WPA2-PSK');
  const [configuring, setConfiguring] = useState(false);
  const [existingSettings, setExistingSettings] = useState<any>(null);
  const [taskId, setTaskId] = useState<number | null>(null);
  const [taskStatus, setTaskStatus] = useState<string | null>(null);

  useEffect(() => {
    const fetchWifiSettings = async () => {
      try {
        const formData = new FormData();
        formData.append('device_id', deviceId);
        formData.append('action', 'get_wifi_settings');

        const response = await fetch('/backend/api/device_configure.php', {
          method: 'POST',
          body: formData
        });

        const result = await response.json();
        console.log("Fetched WiFi settings:", result);

        if (result.success && result.settings) {
          setSsid(result.settings.ssid || '');
          setSecurity(result.settings.security || 'WPA2-PSK');
          setExistingSettings(result.settings);
        }
      } catch (error) {
        console.error('Error fetching WiFi settings:', error);
      }
    };

    fetchWifiSettings();
  }, [deviceId]);

  // Poll for task status if we have a task ID
  useEffect(() => {
    if (!taskId) return;
    
    const checkTaskStatus = async () => {
      try {
        const response = await fetch(`/backend/api/rest/tasks.php?id=${taskId}`);
        const result = await response.json();
        
        if (result.success && result.task) {
          setTaskStatus(result.task.status);
          
          // If the task is completed or failed, stop polling
          if (result.task.status === 'completed') {
            toast.success("WiFi configuration applied successfully!");
            setTaskId(null);
          } else if (result.task.status === 'failed') {
            toast.error("WiFi configuration failed: " + (result.task.message || "Unknown error"));
            setTaskId(null);
          }
        }
      } catch (error) {
        console.error('Error checking task status:', error);
      }
    };
    
    // Check immediately
    checkTaskStatus();
    
    // Then set up interval to check every 5 seconds
    const interval = setInterval(checkTaskStatus, 5000);
    
    // Clean up on unmount
    return () => clearInterval(interval);
  }, [taskId]);

  const makeConfigRequest = async () => {
    setConfiguring(true);
    try {
      if (!ssid) {
        toast.error("SSID cannot be empty");
        setConfiguring(false);
        return;
      }
      
      const formData = new FormData();
      formData.append('device_id', deviceId);
      formData.append('action', 'configure_wifi');
      formData.append('ssid', ssid);
      formData.append('password', password);
      formData.append('security', security);

      console.log(`Making WiFi config request with SSID: ${ssid}, Security: ${security}`);
      
      const response = await fetch('/backend/api/device_configure.php', {
        method: 'POST',
        body: formData
      });

      const result = await response.json();
      console.log(`WiFi config response:`, result);

      if (result.success) {
        toast.success("WiFi configuration request sent");
        
        if (result.connection_request && onSuccess) {
          onSuccess(result.connection_request);
        }
        
        if (result.task_id) {
          setTaskId(result.task_id);
          setTaskStatus('pending');
          
          toast(
            <div className="space-y-2">
              <p className="font-medium">WiFi Configuration Request Sent</p>
              <p className="text-sm">The router will update its WiFi settings on the next TR-069 session.</p>
              <p className="text-sm">Task ID: {result.task_id}</p>
            </div>,
            { duration: 6000 }
          );
        }
      } else {
        toast.error(result.message || 'WiFi configuration failed');
      }
    } catch (error) {
      console.error(`Error in WiFi config:`, error);
      toast.error('WiFi configuration failed due to server error');
    } finally {
      setConfiguring(false);
    }
  };

  return (
    <div>
      <h3 className="text-lg font-bold mb-3 flex items-center gap-2">
        <Wifi className="h-5 w-5" />
        WiFi Configuration
      </h3>

      {existingSettings && (
        <Alert className="mb-4 bg-blue-50 border-blue-200">
          <AlertTriangle className="h-4 w-4 text-blue-500" />
          <AlertTitle className="text-blue-800">Current Configuration</AlertTitle>
          <AlertDescription className="text-blue-700 text-sm">
            {existingSettings.ssid && (
              <div>Current SSID: <strong>{existingSettings.ssid}</strong></div>
            )}
            {existingSettings.security && (
              <div>Security Type: <strong>{existingSettings.security}</strong></div>
            )}
          </AlertDescription>
        </Alert>
      )}
      
      {taskStatus && taskId && (
        <Alert className="mb-4 bg-yellow-50 border-yellow-200">
          <ShieldCheck className="h-4 w-4 text-yellow-500" />
          <AlertTitle className="text-yellow-800">Configuration in Progress</AlertTitle>
          <AlertDescription className="text-yellow-700 text-sm">
            <div>Task ID: <strong>{taskId}</strong></div>
            <div>Status: <strong className="capitalize">{taskStatus}</strong></div>
            <div className="text-xs mt-1">The status will automatically update every few seconds.</div>
          </AlertDescription>
        </Alert>
      )}
      
      <div className="space-y-4">
        <div className="space-y-2">
          <Label htmlFor="ssid">WiFi Network Name (SSID)</Label>
          <Input
            id="ssid"
            placeholder="Enter WiFi name"
            value={ssid}
            onChange={(e) => setSsid(e.target.value)}
            disabled={!!taskId}
          />
        </div>
        
        <div className="space-y-2">
          <Label htmlFor="password">WiFi Password</Label>
          <Input
            id="password"
            type="password"
            placeholder="Enter WiFi password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            disabled={!!taskId}
          />
          <p className="text-xs text-gray-500">
            Minimum 8 characters recommended for security.
          </p>
        </div>
        
        <div className="space-y-2">
          <Label htmlFor="security">Security Type</Label>
          <Select 
            value={security} 
            onValueChange={setSecurity}
            disabled={!!taskId}
          >
            <SelectTrigger className="w-full">
              <SelectValue placeholder="Select security type" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="WPA2-PSK">WPA2-PSK (Recommended)</SelectItem>
              <SelectItem value="WPA-PSK">WPA-PSK</SelectItem>
              <SelectItem value="WPA3-PSK">WPA3-PSK</SelectItem>
              <SelectItem value="WEP">WEP (Not Recommended)</SelectItem>
              <SelectItem value="NONE">None (Unsecured)</SelectItem>
            </SelectContent>
          </Select>
        </div>

        <Alert className="bg-yellow-50 border-yellow-200">
          <AlertTriangle className="h-4 w-4 text-yellow-500" />
          <AlertTitle className="text-yellow-800">Important Note</AlertTitle>
          <AlertDescription className="text-yellow-700 text-sm">
            Changing WiFi settings will disconnect all wireless clients. They will need to reconnect with the new settings.
          </AlertDescription>
        </Alert>
        
        <Button
          onClick={makeConfigRequest}
          className="w-full md:w-auto"
          disabled={configuring || !ssid || !!taskId}
        >
          {configuring ? (
            <>
              <span className="animate-spin h-4 w-4 border-2 border-current border-t-transparent rounded-full mr-2"></span>
              Updating WiFi...
            </>
          ) : taskId ? "WiFi Update in Progress..." : "Update WiFi Configuration"}
        </Button>
      </div>
    </div>
  );
};

export default WifiConfiguration;
