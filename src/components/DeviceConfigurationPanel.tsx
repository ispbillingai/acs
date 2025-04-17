
import React, { useState, useEffect } from 'react';
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { toast } from "sonner";
import { Wifi, Globe, PowerOff } from "lucide-react";

interface DeviceConfigurationPanelProps {
  deviceId: string;
}

export const DeviceConfigurationPanel: React.FC<DeviceConfigurationPanelProps> = ({ deviceId }) => {
  const [wifiSSID, setWifiSSID] = useState('');
  const [wifiPassword, setWifiPassword] = useState('');
  const [wanIPAddress, setWanIPAddress] = useState('');
  const [wanGateway, setWanGateway] = useState('');
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    console.log("DeviceConfigurationPanel mounted with deviceId:", deviceId);
    
    // Fetch current device settings when component mounts
    const fetchDeviceSettings = async () => {
      try {
        const formData = new FormData();
        formData.append('device_id', deviceId);
        formData.append('action', 'get_settings');
        
        const response = await fetch('/backend/api/device_configure.php', {
          method: 'POST',
          body: formData
        });
        
        const result = await response.json();
        if (result.success && result.settings) {
          setWifiSSID(result.settings.ssid || '');
          setWifiPassword(result.settings.password || '');
          setWanIPAddress(result.settings.ip_address || '');
          setWanGateway(result.settings.gateway || '');
        }
      } catch (error) {
        console.error('Error fetching device settings:', error);
      }
    };
    
    fetchDeviceSettings();
  }, [deviceId]);

  const makeConfigRequest = async (action: string, data: Record<string, string>) => {
    setLoading(true);
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
      } else {
        toast.error(result.message || 'Configuration failed');
      }
    } catch (error) {
      console.error(`Error in ${action} config:`, error);
      toast.error('Configuration failed due to server error');
    } finally {
      setLoading(false);
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
            disabled={loading}
          >
            {loading ? "Updating..." : "Update WiFi"}
          </Button>
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
            disabled={loading}
          >
            {loading ? "Updating..." : "Update WAN"}
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
          disabled={loading}
        >
          {loading ? "Processing..." : "Reboot Device"}
        </Button>
      </div>
    </div>
  );
};

export default DeviceConfigurationPanel;
