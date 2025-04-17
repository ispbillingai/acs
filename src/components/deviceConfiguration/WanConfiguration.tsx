
import React, { useState } from 'react';
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { toast } from "sonner";
import { Globe, Server } from "lucide-react";
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
        toast.success(result.message || "WAN configuration updated successfully");
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
          
          {connectionType === "PPPoE" && (
            <div className="space-y-4 bg-gray-50 p-4 rounded-md">
              <h4 className="text-sm font-medium flex items-center gap-2">
                <Server className="h-4 w-4" />
                PPPoE Settings
              </h4>
              <div className="space-y-3">
                <div className="space-y-2">
                  <Label htmlFor="pppoeUsername">Username</Label>
                  <Input
                    id="pppoeUsername"
                    {...form.register("pppoeUsername")}
                    placeholder="Enter ISP provided username"
                  />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="pppoePassword">Password</Label>
                  <Input
                    id="pppoePassword"
                    type="password"
                    {...form.register("pppoePassword")}
                    placeholder="Enter ISP provided password"
                  />
                </div>
              </div>
            </div>
          )}
          
          {connectionType === "Static" && (
            <div className="space-y-4 bg-gray-50 p-4 rounded-md">
              <h4 className="text-sm font-medium flex items-center gap-2">
                <Server className="h-4 w-4" />
                Static IP Settings
              </h4>
              <div className="space-y-3">
                <div className="space-y-2">
                  <Label htmlFor="ipAddress">IP Address</Label>
                  <Input
                    id="ipAddress"
                    {...form.register("ipAddress")}
                    placeholder="e.g., 192.168.1.100"
                  />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="subnetMask">Subnet Mask</Label>
                  <Input
                    id="subnetMask"
                    {...form.register("subnetMask")}
                    placeholder="e.g., 255.255.255.0"
                  />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="gateway">Default Gateway</Label>
                  <Input
                    id="gateway"
                    {...form.register("gateway")}
                    placeholder="e.g., 192.168.1.1"
                  />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="dnsServer1">Primary DNS Server</Label>
                  <Input
                    id="dnsServer1"
                    {...form.register("dnsServer1")}
                    placeholder="e.g., 8.8.8.8"
                  />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="dnsServer2">Secondary DNS Server (Optional)</Label>
                  <Input
                    id="dnsServer2"
                    {...form.register("dnsServer2")}
                    placeholder="e.g., 8.8.4.4"
                  />
                </div>
              </div>
            </div>
          )}
          
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
