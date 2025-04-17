
import React, { useState } from 'react';
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { toast } from "sonner";
import { PowerOff, AlertTriangle } from "lucide-react";
import {
  Alert,
  AlertDescription,
  AlertTitle,
} from "@/components/ui/alert";

interface RebootConfigurationProps {
  deviceId: string;
  onSuccess?: (connectionRequest: any) => void;
}

const RebootConfiguration: React.FC<RebootConfigurationProps> = ({ 
  deviceId,
  onSuccess
}) => {
  const [rebootReason, setRebootReason] = useState('User initiated reboot');
  const [configuring, setConfiguring] = useState(false);

  const makeConfigRequest = async () => {
    setConfiguring(true);
    try {
      console.log(`Making reboot request with reason: ${rebootReason}`);
      
      const formData = new FormData();
      formData.append('device_id', deviceId);
      formData.append('action', 'reboot');
      formData.append('reason', rebootReason);

      const response = await fetch('/backend/api/device_configure.php', {
        method: 'POST',
        body: formData
      });

      if (!response.ok) {
        throw new Error(`Server returned ${response.status}: ${response.statusText}`);
      }

      const result = await response.json();
      console.log(`Reboot config response:`, result);

      if (result.success) {
        toast.success(result.message);
        
        if (result.connection_request && onSuccess) {
          onSuccess(result.connection_request);
        }
        
        toast.info(
          <div className="space-y-2">
            <p className="font-medium">Reboot Command Sent</p>
            <p className="text-sm">The device will reboot after the next TR-069 session.</p>
            <p className="text-sm">Devices typically take 1-2 minutes to reboot completely.</p>
          </div>,
          { duration: 10000 }
        );
      } else {
        toast.error(result.message || 'Reboot configuration failed');
      }
    } catch (error) {
      console.error(`Error in reboot config:`, error);
      toast.error('Reboot configuration failed due to server error');
    } finally {
      setConfiguring(false);
    }
  };

  const handleReboot = () => {
    console.log("Reboot button clicked");
    if (window.confirm(`Are you sure you want to reboot this device? Reason: ${rebootReason}`)) {
      makeConfigRequest();
    }
  };

  return (
    <div>
      <h3 className="text-lg font-bold mb-3 flex items-center gap-2">
        <PowerOff className="h-5 w-5" />
        Device Control
      </h3>
      
      <div className="space-y-4">
        <Alert className="bg-red-50 border-red-200">
          <AlertTriangle className="h-4 w-4 text-red-500" />
          <AlertTitle className="text-red-800">Device Reboot Warning</AlertTitle>
          <AlertDescription className="text-red-700 text-sm">
            Rebooting the device will disconnect all users and services. This operation typically takes 1-2 minutes to complete.
          </AlertDescription>
        </Alert>
        
        <div className="space-y-2">
          <Label htmlFor="reboot-reason">Reboot Reason (optional)</Label>
          <Input
            id="reboot-reason"
            placeholder="User initiated reboot"
            value={rebootReason}
            onChange={(e) => setRebootReason(e.target.value)}
          />
          <p className="text-xs text-gray-500">This will be logged in the device's reboot history.</p>
        </div>
        
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

export default RebootConfiguration;
