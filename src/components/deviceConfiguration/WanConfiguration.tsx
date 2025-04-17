
import React, { useState } from 'react';
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { toast } from "sonner";
import { Globe } from "lucide-react";

interface WanConfigurationProps {
  deviceId: string;
}

const WanConfiguration: React.FC<WanConfigurationProps> = ({ deviceId }) => {
  const [wanIPAddress, setWanIPAddress] = useState('');
  const [wanGateway, setWanGateway] = useState('');
  const [configuring, setConfiguring] = useState(false);

  const makeConfigRequest = async () => {
    setConfiguring(true);
    try {
      console.log(`Making WAN config request with data:`, { ip_address: wanIPAddress, gateway: wanGateway });
      
      const formData = new FormData();
      formData.append('device_id', deviceId);
      formData.append('action', 'wan');
      formData.append('ip_address', wanIPAddress);
      formData.append('gateway', wanGateway);

      const response = await fetch('/backend/api/device_configure.php', {
        method: 'POST',
        body: formData
      });

      if (!response.ok) {
        throw new Error(`Server returned ${response.status}: ${response.statusText}`);
      }

      const result = await response.json();
      console.log(`WAN config response:`, result);

      if (result.success) {
        toast.success(result.message);
      } else {
        toast.error(result.message || 'Configuration failed');
      }
    } catch (error) {
      console.error(`Error in WAN config:`, error);
      toast.error('Configuration failed due to server error');
    } finally {
      setConfiguring(false);
    }
  };

  const handleWANUpdate = () => {
    console.log("WAN update button clicked");
    if (!wanIPAddress.trim()) {
      toast.error('IP Address cannot be empty');
      return;
    }
    makeConfigRequest();
  };

  return (
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
  );
};

export default WanConfiguration;
