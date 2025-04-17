
import React, { useState, useEffect } from 'react';
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { toast } from "sonner";
import { Wifi, Globe, PowerOff, AlertTriangle } from "lucide-react";

interface DeviceConfigurationPanelProps {
  deviceId: string;
}

export const DeviceConfigurationPanel: React.FC<DeviceConfigurationPanelProps> = ({ deviceId }) => {
  const [wifiSSID, setWifiSSID] = useState('');
  const [wifiPassword, setWifiPassword] = useState('');
  const [wanIPAddress, setWanIPAddress] = useState('');
  const [wanGateway, setWanGateway] = useState('');
  const [loading, setLoading] = useState(false);
  const [configuring, setConfiguring] = useState(false);

  useEffect(() => {
    console.log("DeviceConfigurationPanel mounted with deviceId:", deviceId);
    
    // Fetch current device settings when component mounts
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
        console.log("Fetched device settings:", result);
        
        if (result.success && result.settings) {
          setWifiSSID(result.settings.ssid || '');
          setWifiPassword(result.settings.password || '');
          setWanIPAddress(result.settings.ip_address || '');
          setWanGateway(result.settings.gateway || '');
        } else {
          toast.error("Failed to load device settings");
        }
      } catch (error) {
        console.error('Error fetching device settings:', error);
        toast.error("Error loading device settings");
      } finally {
        setLoading(false);
      }
    };
    
    fetchDeviceSettings();
  }, [deviceId]);

  const makeConfigRequest = async (action: string, data: Record<string, string>) => {
    setConfiguring(true);
    try {
      console.log(`Making ${action} config request with data:`, data);
      
      const formData = new FormData();
      formData.append('device_id', deviceId);
      formData.append('action', action);

      Object.entries(data).forEach(([key, value]) => {
        formData.append(key, value);
      });

      const response = await fetch('/backend/api/device_configure.php', {
        method: 'POST',
        body: formData
      });

      const result = await response.json();
      console.log(`${action} config response:`, result);

      if (result.success) {
        toast.success(result.message);
        
        // For WiFi configuration, show an additional information toast
        if (action === 'wifi') {
          toast.info(
            <div className="flex items-start gap-2">
              <AlertTriangle className="h-5 w-5 text-yellow-500 flex-shrink-0 mt-0.5" />
              <div>
                <p className="font-medium">Configuration in progress</p>
                <p className="text-sm">Changes may take 30-60 seconds to apply on the device.</p>
              </div>
            </div>,
            { duration: 10000 }
          );
        }
      } else {
        toast.error(result.message || 'Configuration failed');
      }
    } catch (error) {
      console.error(`Error in ${action} config:`, error);
      toast.error('Configuration failed due to server error');
    } finally {
      setConfiguring(false);
    }
  };

  const handleWiFiUpdate = () => {
    console.log("WiFi update button clicked");
    if (!wifiSSID.trim()) {
      toast.error('WiFi SSID cannot be empty');
      return;
    }
    makeConfigRequest('wifi', { ssid: wifiSSID, password: wifiPassword });
  };

  const handleWANUpdate = () => {
    console.log("WAN update button clicked");
    if (!wanIPAddress.trim()) {
      toast.error('IP Address cannot be empty');
      return;
    }
    makeConfigRequest('wan', { ip_address: wanIPAddress, gateway: wanGateway });
  };

  const handleReboot = () => {
    console.log("Reboot button clicked");
    if (window.confirm('Are you sure you want to reboot this device?')) {
      makeConfigRequest('reboot', {});
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
      <div>
        <h3 className="text-lg font-bold mb-3 flex items-center gap-2">
          <Wifi className="h-5 w-5" />
          WiFi Configuration
        </h3>
        <div className="space-y-3">
          <Input
            placeholder="WiFi Network Name"
            value={wifiSSID}
            onChange={(e) => setWifiSSID(e.target.value)}
          />
          <Input
            type="password"
            placeholder="WiFi Password"
            value={wifiPassword}
            onChange={(e) => setWifiPassword(e.target.value)}
          />
          <Button 
            onClick={handleWiFiUpdate}
            className="w-full md:w-auto"
            disabled={configuring}
          >
            {configuring ? (
              <>
                <span className="animate-spin h-4 w-4 border-2 border-current border-t-transparent rounded-full mr-2"></span>
                Updating...
              </>
            ) : "Update WiFi"}
          </Button>
          <p className="text-xs text-gray-500 mt-1">
            Note: WiFi changes may take 30-60 seconds to apply on the router.
          </p>
        </div>
      </div>

      <div>
        <h3 className="text-lg font-bold mb-3 flex items-center gap-2">
          <Globe className="h-5 w-5" />
          WAN Configuration
        </h3>
        <div className="space-y-3">
          <Input
            placeholder="IP Address"
            value={wanIPAddress}
            onChange={(e) => setWanIPAddress(e.target.value)}
          />
          <Input
            placeholder="Gateway"
            value={wanGateway}
            onChange={(e) => setWanGateway(e.target.value)}
          />
          <Button 
            onClick={handleWANUpdate}
            className="w-full md:w-auto"
            disabled={configuring}
          >
            {configuring ? (
              <>
                <span className="animate-spin h-4 w-4 border-2 border-current border-t-transparent rounded-full mr-2"></span>
                Updating...
              </>
            ) : "Update WAN"}
          </Button>
        </div>
      </div>

      <div>
        <h3 className="text-lg font-bold mb-3 flex items-center gap-2">
          <PowerOff className="h-5 w-5" />
          Device Control
        </h3>
        <Button 
          variant="destructive" 
          onClick={handleReboot}
          className="w-full md:w-auto"
          disabled={configuring}
        >
          {configuring ? (
            <>
              <span className="animate-spin h-4 w-4 border-2 border-current border-t-transparent rounded-full mr-2"></span>
              Processing...
            </>
          ) : "Reboot Device"}
        </Button>
      </div>
    </div>
  );
};

export default DeviceConfigurationPanel;
