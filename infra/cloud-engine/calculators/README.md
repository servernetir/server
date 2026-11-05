# Capacity and commercial model

```sh
python3 infra/cloud-engine/calculators/model.py \
  infra/cloud-engine/calculators/synthetic.example.json --output /tmp/cloud-engine-model.json
python3 -m unittest discover -s infra/cloud-engine/calculators -p 'test_*.py'
```

The example contains synthetic test inputs. It is **not** a current supplier
quotation, an FX quote, a measured workload or evidence that this retail price
is viable. Replace every quoted/measured input and record its source/date before
the commercial purchase gate. The calculator never authorizes a purchase.

Capacity is the minimum of independently budgeted CPU, RAM, usable mirrored disk,
sustained measured IOPS, network, monthly traffic and guest IPv4. Logical threads
are reported but never counted as physical cores. Default CPU allocation is 1:1;
ratios above one require a written workload/performance justification and are
reported in output. RAM is never overcommitted. Ballooning, compression and ZFS
deduplication are not capacity guarantees. Include per-VM emulator overhead and
host/ZFS/backup safety reserves. Disk input is post-mirror usable GiB.

Reports cover 1vCPU/1GiB, 1/2, 2/2 and 2/4 at 10,20,30,50,100 customers. Scenarios
above a node's capacity pay for `ceil(customers/capacity)` nodes, including each
node's subnet/setup/backup/overhead cost. Thus 100 customers are not silently
modeled on one undersized server. A first break-even point does not imply every
later occupancy remains profitable because purchasing another node adds a cost
step. The search limit is explicit.

All monetary arithmetic uses Decimal. Retail, fee and converted amounts are
**IRT (Iranian Tomans)**; 1 IRT = 10 IRR. The default target is **1,000,000 IRT per
month**. Supplier costs use EUR and FX is **IRT per EUR**. Supplier VAT, customer
sales VAT and payment fees are separate. One-time node/subnet setup is amortized
over the selected months. Output preserves two decimal places for analytical
comparison; it is not an invoice or a second billing ledger. Expected resale
capacity is constrained by traffic and I/O assumptions as well as memory.
