<?php

class UserLoader {
	function __construct($conn) {
		$this->conn = $conn;
	}

	function load($email) {
		$result = $this->conn->query(
			"select id,secret,probeHMAC,status,administrator from users where email = ?",
			array($email)
			);

		if ($result->num_rows == 0) {
			throw new UserLookupError();
		}
		$row = $result->fetch_assoc();
		return $row;
	}
}

class ProbeLoader {
	function __construct($conn) {
		$this->conn = $conn;
	}

	function load($probe_uuid) {
		$result = $this->conn->query(
			"select * from probes where uuid=?",
			array($probe_uuid)
			);
		if ($result->num_rows == 0) {
			throw new ProbeLookupError();
		}
		$row = $result->fetch_assoc();
		return $row;
	}

	function updateReqSent($probe_uuid) {
		# increment the requests sent counter on the probe record
		$result = $this->conn->query(
			"update probes set probeReqSent=probeReqSent+1,lastSeen where uuid=?",
			array($probe_uuid)
			);
		if ($this->conn->affected_rows != 1) {
			throw new ProbeLookupError();
		}
	}

	function updateRespRecv($probe_uuid) {
		# increment the responses recd counter on the probe record
		$result = $this->conn->query(
			"update probes set probeRespRecv=probeRespRecv+1,lastSeen=now() where uuid=?",
			array($probe_uuid)
			);
		if ($this->conn->affected_rows != 1) {
			throw new ProbeLookupError();
		}
	}

}

class UrlLoader {
	function __construct($conn) {
		$this->conn = $conn;
	}

	function load($url) {
		$result = $this->conn->query(
			"select * from urls where URL=?",
			array($url)
			);
		if ($result->num_rows == 0) {
			throw new UrlLookupError();
		}
		$row = $result->fetch_assoc();
		return $row;
	}

	function get_next_old() {
		$result = $this->conn->query("select urlID,URL,hash from urls where lastPolled is null or lastPolled < date_sub(now(), interval 12 hour) ORDER BY lastPolled ASC,polledAttempts DESC LIMIT 1", array());
		if ($result->num_rows == 0) {
			return null;
		}
		$row = $result->fetch_assoc();
		return $row;
	}

	function get_next($ispid) {
		/*
		The main queue query.  This prioritises URLs to check in order of results received, 
		aiming to gain multiple opinions of a URL's status on a given ISP as quickly as possible.
		The URL list cycles once per day.

		It is possible for the same probe to handle a single URL on multiple successive days.
		Trying to control which probe gets which URLs can be done, but it's a trade-off between 
		fine-grained control and queue management expense.  This would involve a lumpy left-join or
		burning through results until we find one that hasn't been sent to the probe before.

		This won't scale wonderfully, but it's a start.

		The main query does at least have a covering index, so it's a bit less expensive to start 
		with (no filesort!).
		*/

		$result = $this->conn->query(
			"select URL, urls.urlID, queue.id, hash from urls
			inner join queue on queue.urlID = urls.urlID
			where queue.ispID = ? and (lastSent < date_sub(now(), interval 1 day) or lastSent is null)
			order by queue.priority,queue.results, queue.lastSent
			limit 1",
			array($ispid)
			);

		if ($result->num_rows == 0) {
			# return null when we don't have any queued URLs to return.
			return null;
		}

		# get the row
		$row = $result->fetch_assoc();

		# update the lastSent timestamp to keep queue entries rolling around
		$this->conn->query(
			"update queue set lastSent = now() where id = ?",
			array($row['id'])
			);

		# update the poll counter on  the URL record
		$this->conn->query(
			"update urls set lastPolled = now(), polledAttempts = polledAttempts + 1 where urlID = ?",
			array($row['urlID'])
			);

		return $row;
	}
}

class IspLoader {
	function __construct($conn) {
		$this->conn = $conn;
	}

	function load($ispname) {
		$result = $this->conn->query(
			"select isps.* from isps left join isp_aliases on isp_aliases.ispID = isps.id where name = ? or alias = ?",
			array($ispname,$ispname)
			);
		if (!$result) {
			throw new IspLookupError();
		}
		$row = $result->fetch_assoc();
		return $row;
	}

	function create($name) {
		$result = $this->conn->query(
			"insert ignore into isps(name,created) values (?, now())",
			array($name)
			);
		if (!$result) {
			throw new DatabaseError();
		}
	}
}

class IpLookupService {
	function __construct($conn) {
		$this->conn = $conn;
	}

	function check_cache($ip) {
		error_log("Checking cache for $ip");
		$result = $this->conn->query(
			"select network from isp_cache where ip = ? and 
			created >= date_sub(current_date, interval 7 day)",
			array($ip)
			);
		if (!$result) {
			return null;
		}
		$row = $result->fetch_assoc();
		if (!$row) {
			return null;
		}
		return $row['network'];
	}

	function write_cache($ip, $network) {
		error_log("Writing cache entry for $ip, $network");
		$this->conn->query(
			"insert into isp_cache(ip, network, created) 
			values (?, ?, now())
			on duplicate key update created = current_date",
		array($ip, $network)
		);
		error_log("Cache write complete");
	}

	function lookup($ip) {
		# run a whois query for the IP address

		$descr = $this->check_cache($ip);
		if ($descr == null) {
			error_log("Cache miss for $ip");
			$cmd = "/usr/bin/whois '" . escapeshellarg($ip) . "'";

			$fp = popen($cmd, 'r');
			if (!$fp) {
				throw new IpLookupError();
			}
			$descr = '';
			while (!feof($fp)) {
				$line = fgets($fp);
				$parts = explode(":",chop($line));
				if ($parts[0] == "descr") {
					# save the value of the last descr tag, seems to work in most cases
					$descr = trim($parts[1]);
				}
			}
			fclose($fp);

			if (!$descr) {
				throw new IpLookupError();
			}
			$this->write_cache($ip, $descr);
		} else {
			error_log("Cache hit");
		}
		return $descr;
	}
}
