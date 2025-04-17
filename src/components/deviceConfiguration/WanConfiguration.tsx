
import React, { useState, useEffect } from 'react';
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { toast } from "sonner";
import { Globe, Server, AlertCircle } from "lucide-react";
import { RadioGroup, RadioGroupItem } from "@/components/ui/radio-group";
import { Form, FormControl, FormField, FormItem, FormLabel } from "@/components/ui/form";
import { useForm } from "react-hook-form";

interface WanConfigurationProps {
  deviceId: string;
}

type ConnectionType = "DHCP" | "PPPoE" | "Static";

interface WanFormValues {
  connectionType: ConnectionType;
  ipAddress?: string;
  subnetMask?: string;
  gateway?: string;
  dnsServer1?: string;
  dnsServer2?: string;
  pppoeUsername?: string;
  pppoePassword?: string;
}

const WanConfiguration: React.FC<WanConfigurationProps> = ({ deviceId }) => {
  const [configuring, setConfiguring] = useState(false);
  const [taskStatus, setTaskStatus] = useState<string | null>(null);
  
  const form = useForm<WanFormValues>({
    defaultValues: {
      connectionType: "DHCP",
      ipAddress: "",
      subnetMask: "",
      gateway: "",
      dnsServer1: "",
      dnsServer2: "",
      pppoeUsername: "",
      pppoePassword: ""
    }
  });

  const connectionType = form.watch("connectionType");
  
  // Poll for task status if a task is in progress
  useEffect(() => {
    let taskId: string | null = null;
    let intervalId: NodeJS.Timeout;
    
    if (configuring) {
      intervalId = setInterval(async () => {
        if (!taskId) return;
        
        try {
          const formData = new FormData();
          formData.append('task_id', taskId);
          formData.append('action', 'check_task_status');
          
          const response = await fetch('/backend/api/device_configure.php', {
            method: 'POST',
            body: formData
          });
          
          if (!response.ok) {
            throw new Error('Failed to fetch task status');
          }
          
          const result = await response.json();
          console.log('Task status check result:', result);
          
          if (result.success && result.status) {
            setTaskStatus(result.status);
            
            if (result.status === 'completed') {
              setConfiguring(false);
              toast.success('WAN configuration applied successfully');
              clearInterval(intervalId);
            } else if (result.status === 'failed') {
              setConfiguring(false);
              toast.error(`Configuration failed: ${result.message || 'Unknown error'}`);
              clearInterval(intervalId);
            }
          }
        } catch (error) {
          console.error('Error checking task status:', error);
        }
      }, 3000); // Check every 3 seconds
    }
    
    return () => {
      if (intervalId) clearInterval(intervalId);
    };
  }, [configuring]);

  const makeConfigRequest = async (values: WanFormValues) => {
    setConfiguring(true);
    try {
      console.log(`Making WAN config request with data:`, values);
      
      const formData = new FormData();
      formData.append('device_id', deviceId);
      formData.append('action', 'wan');
      formData.append('connection_type', values.connectionType);
      
      // Add connection-type specific parameters
      if (values.connectionType === "Static") {
        formData.append('ip_address', values.ipAddress || "");
        formData.append('subnet_mask', values.subnetMask || "");
        formData.append('gateway', values.gateway || "");
        formData.append('dns_server1', values.dnsServer1 || "");
        formData.append('dns_server2', values.dnsServer2 || "");
      } else if (values.connectionType === "PPPoE") {
        formData.append('pppoe_username', values.pppoeUsername || "");
        formData.append('pppoe_password', values.pppoePassword || "");
      }
      // For DHCP, no additional parameters needed

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
        // Store the task ID for status polling
        if (result.task_id) {
          window.taskId = result.task_id;
          setTaskStatus('pending');
          toast.success("WAN configuration request sent to device");
        } else {
          toast.success(result.message || "WAN configuration updated successfully");
          setConfiguring(false);
        }
      } else {
        toast.error(result.message || 'Configuration failed');
        setConfiguring(false);
      }
    } catch (error) {
      console.error(`Error in WAN config:`, error);
      toast.error('Configuration failed due to server error');
      setConfiguring(false);
    }
  };

  const onSubmit = (values: WanFormValues) => {
    console.log("WAN update form submitted", values);
    makeConfigRequest(values);
  };

  return (
    <div>
      <h3 className="text-lg font-bold mb-3 flex items-center gap-2">
        <Globe className="h-5 w-5" />
        WAN Configuration
      </h3>
      
      {taskStatus === 'in_progress' && (
        <div className="bg-blue-50 border border-blue-200 text-blue-800 p-3 rounded-md mb-4 flex items-center gap-2">
          <div className="animate-spin h-4 w-4 border-2 border-blue-600 border-t-transparent rounded-full"></div>
          <p>Applying WAN configuration to device... This may take a moment.</p>
        </div>
      )}
      
      {taskStatus === 'failed' && (
        <div className="bg-red-50 border border-red-200 text-red-800 p-3 rounded-md mb-4 flex items-center gap-2">
          <AlertCircle className="h-4 w-4" />
          <p>Failed to apply WAN configuration. Please try again.</p>
        </div>
      )}
      
      <Form {...form}>
        <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
          <FormField
            control={form.control}
            name="connectionType"
            render={({ field }) => (
              <FormItem className="space-y-3">
                <FormLabel>Connection Type</FormLabel>
                <FormControl>
                  <RadioGroup
                    onValueChange={field.onChange}
                    defaultValue={field.value}
                    className="flex flex-col space-y-1"
                  >
                    <div className="flex items-center space-x-2">
                      <RadioGroupItem value="DHCP" id="dhcp" />
                      <Label htmlFor="dhcp">DHCP (Automatic)</Label>
                    </div>
                    <div className="flex items-center space-x-2">
                      <RadioGroupItem value="PPPoE" id="pppoe" />
                      <Label htmlFor="pppoe">PPPoE</Label>
                    </div>
                    <div className="flex items-center space-x-2">
                      <RadioGroupItem value="Static" id="static" />
                      <Label htmlFor="static">Static IP</Label>
                    </div>
                  </RadioGroup>
                </FormControl>
              </FormItem>
            )}
          />
          
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            {/* PPPoE Settings */}
            <div className="space-y-4 bg-gray-50 p-4 rounded-md">
              <h4 className="text-sm font-medium flex items-center gap-2">
                <Server className="h-4 w-4" />
                PPPoE Settings {connectionType === "PPPoE" && "(Active)"}
              </h4>
              <div className="space-y-3">
                <div className="space-y-2">
                  <Label htmlFor="pppoeUsername" className={connectionType !== "PPPoE" ? "text-gray-400" : ""}>
                    Username
                  </Label>
                  <Input
                    id="pppoeUsername"
                    {...form.register("pppoeUsername")}
                    placeholder="Enter ISP provided username"
                    disabled={connectionType !== "PPPoE" || configuring}
                    className={connectionType !== "PPPoE" ? "bg-gray-100 text-gray-500" : ""}
                  />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="pppoePassword" className={connectionType !== "PPPoE" ? "text-gray-400" : ""}>
                    Password
                  </Label>
                  <Input
                    id="pppoePassword"
                    type="password"
                    {...form.register("pppoePassword")}
                    placeholder="Enter ISP provided password"
                    disabled={connectionType !== "PPPoE" || configuring}
                    className={connectionType !== "PPPoE" ? "bg-gray-100 text-gray-500" : ""}
                  />
                </div>
              </div>
            </div>

            {/* Static IP Settings */}
            <div className="space-y-4 bg-gray-50 p-4 rounded-md">
              <h4 className="text-sm font-medium flex items-center gap-2">
                <Server className="h-4 w-4" />
                Static IP Settings {connectionType === "Static" && "(Active)"}
              </h4>
              <div className="space-y-3">
                <div className="space-y-2">
                  <Label htmlFor="ipAddress" className={connectionType !== "Static" ? "text-gray-400" : ""}>
                    IP Address
                  </Label>
                  <Input
                    id="ipAddress"
                    {...form.register("ipAddress")}
                    placeholder="e.g., 192.168.1.100"
                    disabled={connectionType !== "Static" || configuring}
                    className={connectionType !== "Static" ? "bg-gray-100 text-gray-500" : ""}
                  />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="subnetMask" className={connectionType !== "Static" ? "text-gray-400" : ""}>
                    Subnet Mask
                  </Label>
                  <Input
                    id="subnetMask"
                    {...form.register("subnetMask")}
                    placeholder="e.g., 255.255.255.0"
                    disabled={connectionType !== "Static" || configuring}
                    className={connectionType !== "Static" ? "bg-gray-100 text-gray-500" : ""}
                  />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="gateway" className={connectionType !== "Static" ? "text-gray-400" : ""}>
                    Default Gateway
                  </Label>
                  <Input
                    id="gateway"
                    {...form.register("gateway")}
                    placeholder="e.g., 192.168.1.1"
                    disabled={connectionType !== "Static" || configuring}
                    className={connectionType !== "Static" ? "bg-gray-100 text-gray-500" : ""}
                  />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="dnsServer1" className={connectionType !== "Static" ? "text-gray-400" : ""}>
                    Primary DNS Server
                  </Label>
                  <Input
                    id="dnsServer1"
                    {...form.register("dnsServer1")}
                    placeholder="e.g., 8.8.8.8"
                    disabled={connectionType !== "Static" || configuring}
                    className={connectionType !== "Static" ? "bg-gray-100 text-gray-500" : ""}
                  />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="dnsServer2" className={connectionType !== "Static" ? "text-gray-400" : ""}>
                    Secondary DNS Server (Optional)
                  </Label>
                  <Input
                    id="dnsServer2"
                    {...form.register("dnsServer2")}
                    placeholder="e.g., 8.8.4.4"
                    disabled={connectionType !== "Static" || configuring}
                    className={connectionType !== "Static" ? "bg-gray-100 text-gray-500" : ""}
                  />
                </div>
              </div>
            </div>
          </div>
          
          <Button 
            type="submit"
            className="w-full md:w-auto"
            disabled={configuring}
          >
            {configuring ? (
              <>
                <span className="animate-spin h-4 w-4 border-2 border-current border-t-transparent rounded-full mr-2"></span>
                Updating...
              </>
            ) : "Update WAN Configuration"}
          </Button>
        </form>
      </Form>
    </div>
  );
};

export default WanConfiguration;
