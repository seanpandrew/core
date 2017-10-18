<?php
/**
 * @author Piotr Mrowczynski <piotr@owncloud.com>
 *
 * @copyright Copyright (c) 2017, ownCloud GmbH
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace OC;

use OC\Group\BackendGroup;
use OC\User\Account;
use OCP\AppFramework\Db\Entity;
use OCP\IConfig;
use OCP\IDBConnection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use OCP\DB\QueryBuilder\IQueryBuilder;

class MembershipManager {

	/**
	 * types of memberships in the group
	 */
	const MEMBERSHIP_TYPE_GROUP_USER = 0;
	const MEMBERSHIP_TYPE_GROUP_ADMIN = 1;

	/** @var IConfig */
	protected $config;

	/** @var IDBConnection */
	private $db;

	/** @var \OC\Group\GroupMapper */
	private $groupMapper;

	/** @var \OC\Group\GroupMapper */
	private $accountMapper;

	/** @var \OC\User\AccountTermMapper */
	private $termMapper;

	public function __construct(IDBConnection $db, IConfig $config,
								\OC\User\AccountMapper $accountMapper, \OC\User\AccountTermMapper $termMapper,
								\OC\Group\GroupMapper $groupMapper) {
		$this->db = $db;
		$this->config = $config;
		$this->accountMapper = $accountMapper;
		$this->groupMapper = $groupMapper;
		$this->termMapper = $termMapper;
	}

	private function getTableAlias() {
		return 'm';
	}

	private function getTableName() {
		return 'memberships';
	}

	/**
	 * Return backend group entities for given account (identified by user's uid)
	 *
	 * @param string $userId
	 *
	 * @return BackendGroup[]
	 */
	public function getUserBackendGroups($userId) {
		return $this->getBackendGroupsSqlQuery($userId, false, self::MEMBERSHIP_TYPE_GROUP_USER);
	}

	/**
	 * Return backend group entities for given account (identified by user's internal id)
	 *
	 * NOTE: Search by internal id is used to optimize access when
	 * group backend/account has already been instantiated and internal id is explicitly available
	 *
	 * @param int $accountId
	 *
	 * @return BackendGroup[]
	 */
	public function getUserBackendGroupsById($accountId) {
		return $this->getBackendGroupsSqlQuery($accountId, true, self::MEMBERSHIP_TYPE_GROUP_USER);
	}

	/**
	 * Return backend group entities for given account (identified by user's uid) of which
	 * the user is admin.
	 *
	 * @param string $userId
	 *
	 * @return BackendGroup[]
	 */
	public function getAdminBackendGroups($userId) {
		return $this->getBackendGroupsSqlQuery($userId, false, self::MEMBERSHIP_TYPE_GROUP_ADMIN);
	}

	/**
	 * Return user account entities for given group (identified with gid)
	 *
	 * @param string $gid
	 *
	 * @return Account[]
	 */
	public function getGroupUserAccounts($gid) {
		return $this->getAccountsSqlQuery($gid, false, self::MEMBERSHIP_TYPE_GROUP_USER);
	}

	/**
	 * Return user account entities for given group (identified with group's internal id)
	 *
	 * @param int $backendGroupId
	 *
	 * @return Account[]
	 */
	public function getGroupUserAccountsById($backendGroupId) {
		return $this->getAccountsSqlQuery($backendGroupId, true, self::MEMBERSHIP_TYPE_GROUP_USER);
	}

	/**
	 * Return admin account entities for given group (identified with gid)
	 *
	 * @param string $gid
	 *
	 * @return Account[]
	 */
	public function getGroupAdminAccounts($gid) {
		return $this->getAccountsSqlQuery($gid, false, self::MEMBERSHIP_TYPE_GROUP_ADMIN);

	}

	/**
	 * Return admin account entities for all backend groups
	 *
	 * @return Account[]
	 */
	public function getAdminAccounts() {
		return $this->getAccountsSqlQuery(null, false, self::MEMBERSHIP_TYPE_GROUP_ADMIN);
	}

	/**
	 * Check whether given account (identified by user's uid) is user of
	 * the group (identified with gid)
	 *
	 * @param string $userId
	 * @param string $gid
	 *
	 * @return boolean
	 */
	public function isGroupUser($userId, $gid) {
		return $this->isGroupMemberSqlQuery($userId, $gid, self::MEMBERSHIP_TYPE_GROUP_USER, false);
	}

	/**
	 * Check whether given account (identified by user's internal id) is user of
	 * the group (identified with group's internal id)
	 *
	 * NOTE: Search by internal id is used to optimize access when
	 * group backend/account has already been instantiated and internal id is explicitly available
	 *
	 * @param int $accountId
	 * @param int $backendGroupId
	 *
	 * @return boolean
	 */
	public function isGroupUserById($accountId, $backendGroupId) {
		return $this->isGroupMemberSqlQuery($accountId, $backendGroupId, self::MEMBERSHIP_TYPE_GROUP_USER, true);
	}

	/**
	 * Check whether given account (identified by user's uid) is admin of
	 * the group (identified with gid)
	 *
	 * @param string $userId
	 * @param string $gid
	 *
	 * @return boolean
	 */
	public function isGroupAdmin($userId, $gid) {
		return $this->isGroupMemberSqlQuery($userId, $gid, self::MEMBERSHIP_TYPE_GROUP_ADMIN, false);

	}

	/**
	 * Search for members which match the pattern and
	 * are users in the group (identified with gid)
	 *
	 * @param string $gid
	 * @param string $pattern
	 * @param integer $limit
	 * @param integer $offset
	 * @return Entity[]
	 */
	public function find($gid, $pattern, $limit = null, $offset = null) {
		return $this->searchAccountsSqlQuery($gid, false, $pattern, $limit, $offset);
	}

	/**
	 * Search for members which match the pattern and
	 * are users in the backend group (identified with internal group id $backendGroupId)
	 *
	 * NOTE: Search by internal id instead of gid is used to optimize access when
	 * group backend has already been instantiated and $backendGroupId is explicitly available
	 *
	 * @param int $backendGroupId
	 * @param string $pattern
	 * @param integer $limit
	 * @param integer $offset
	 * @return Entity[]
	 */
	public function findById($backendGroupId, $pattern, $limit = null, $offset = null) {
		return $this->searchAccountsSqlQuery($backendGroupId, true, $pattern, $limit, $offset);
	}

	/**
	 * Count members which match the pattern and
	 * are users in the group (identified with gid)
	 *
	 * @param string $gid
	 * @param string $pattern
	 *
	 * @return int
	 */
	public function count($gid, $pattern) {
		return $this->countSqlQuery($gid, false, $pattern);
	}

	/**
	 * Add a group account (identified by user's internal id $accountId)
	 * to group (identified by group's internal id $backendGroupId).
	 *
	 * @param int $accountId - internal id of an account
	 * @param int $backendGroupId - internal id of backend group
	 *
	 * @return bool
	 */
	public function addGroupUser($accountId, $backendGroupId) {
		return $this->addGroupMemberSqlQuery($accountId, $backendGroupId, self::MEMBERSHIP_TYPE_GROUP_USER);
	}

	/**
	 * Add a group admin account (identified by user's internal id $accountId)
	 * to group (identified by group's internal id $backendGroupId).
	 *
	 * @param int $accountId - internal id of an account
	 * @param int $backendGroupId - internal id of backend group
	 *
	 * @return bool
	 */
	public function addGroupAdmin($accountId, $backendGroupId) {
		return $this->addGroupMemberSqlQuery($accountId, $backendGroupId, self::MEMBERSHIP_TYPE_GROUP_ADMIN);
	}

	/**
	 * Delete a group user (identified by user's uid)
	 * from group.
	 *
	 * @param int $accountId - internal id of an account
	 * @param int $backendGroupId - internal id of backend group
	 * @return bool
	 */
	public function removeGroupUser($accountId, $backendGroupId) {
		return $this->removeGroupMembershipsSqlQuery($backendGroupId, $accountId, [self::MEMBERSHIP_TYPE_GROUP_USER]);
	}

	/**
	 * Delete a group admin (identified by user's uid)
	 * from group.
	 *
	 * @param int $accountId - internal id of an account
	 * @param int $backendGroupId - internal id of backend group
	 * @return bool
	 */
	public function removeGroupAdmin($accountId, $backendGroupId) {
		return $this->removeGroupMembershipsSqlQuery($backendGroupId, $accountId, [self::MEMBERSHIP_TYPE_GROUP_ADMIN]);
	}

	/**
	 * Removes members from group (identified by group's gid),
	 * regardless of the role in the group.
	 *
	 * @param int $backendGroupId - internal id of backend group
	 * @return bool
	 */
	public function removeGroupMembers($backendGroupId) {
		return $this->removeGroupMembershipsSqlQuery($backendGroupId, null, [self::MEMBERSHIP_TYPE_GROUP_USER, self::MEMBERSHIP_TYPE_GROUP_ADMIN]);
	}

	/**
	 * Delete the memberships of user (identified by user's uid),
	 * regardless of the role in the group.
	 *
	 * @param int $accountId - internal id of an account
	 * @return bool
	 */
	public function removeMemberships($accountId) {
		return $this->removeGroupMembershipsSqlQuery(null, $accountId, [self::MEMBERSHIP_TYPE_GROUP_USER, self::MEMBERSHIP_TYPE_GROUP_ADMIN]);
	}

	/**
	 * Check if the given user is member of the group with specific membership type
	 *
	 * @param string|int $userId
	 * @param string|int $groupId
	 * @param string $membershipType
	 * @param bool $useInternalIds
	 *
	 * @return boolean
	 */
	private function isGroupMemberSqlQuery($userId, $groupId, $membershipType, $useInternalIds) {
		$qb = $this->db->getQueryBuilder();
		$alias = $this->getTableAlias();
		$qb->selectAlias($qb->createFunction('1'), 'exists')
			->from($this->getTableName(), $alias);

		if ($useInternalIds) {
			// No need to JOIN any tables, we already have all information required
			// Apply predicate on backend_group_id and account_id in memberships table
			$qb->where($qb->expr()->eq($alias.'.backend_group_id',
				$qb->createNamedParameter($groupId)));
			$qb->andWhere($qb->expr()->eq($alias.'.account_id',
				$qb->createNamedParameter($userId)));
		} else {
			// We need to join with accounts table, since we miss information on accountId
			// We need to join with backend group table, since we miss information on backendGroupId
			$qb->innerJoin($alias, $this->accountMapper->getTableName(),
				'a', $qb->expr()->eq('a.id', $alias.'.account_id'));
			$qb->innerJoin($alias, $this->groupMapper->getTableName(),
				'g', $qb->expr()->eq('g.id', $alias.'.backend_group_id'));

			// Apply predicate on user_id in accounts table
			$qb->where($qb->expr()->eq('a.user_id', $qb->createNamedParameter($userId)));

			// Apply predicate on group_id in backend groups table
			$qb->andWhere($qb->expr()->eq('g.group_id', $qb->createNamedParameter($groupId)));
		}

		// Place predicate on membership_type
		$qb->andWhere($qb->expr()->eq($alias.'.membership_type', $qb->createNamedParameter($membershipType)));

		// Limit to 1, to prevent fetching unnecesary rows
		$qb->setMaxResults(1);

		return $this->getExistsQuery($qb);
	}


	/**
	 * Add user to the group with specific membership type $membershipType.
	 *
	 * //FIXME: If application requires, use INSERT INTO ... SELECT .. FROM ..
	 *
	 * @param int $accountId - internal id of an account
	 * @param int $backendGroupId - internal id of backend group
	 * @param string $membershipType
	 *
	 * Return will indicate if row has been inserted
	 *
	 * @throws UniqueConstraintViolationException
	 * @return boolean
	 */
	private function addGroupMemberSqlQuery($accountId, $backendGroupId, $membershipType) {
		$qb = $this->db->getQueryBuilder();

		$qb->insert($this->getTableName())
			->values([
				'backend_group_id' => $qb->createNamedParameter($backendGroupId),
				'account_id' => $qb->createNamedParameter($accountId),
				'membership_type' => $qb->createNamedParameter($membershipType),
			]);

		return $this->getAffectedQuery($qb);
	}

	/*
	 * Removes users from the groups. If the predicate on a user or group is null, then it will apply
	 * removal to all the entries of that type.
	 *
	 * NOTE: This function requires to use internal IDs, since we cannot
	 * use JOIN with DELETE (some databases don't support it). We also cannot use aliases
	 * since MySQL has specific syntax for them in DELETE
	 *
	 * Return will indicate if row has been removed
	 *
	 * @param int|null $accountId - internal id of an account
	 * @param |null $backendGroupId - internal id of backend group
	 * @param int[] $membershipTypeArray
	 *
	 * @return boolean
	 */
	private function removeGroupMembershipsSqlQuery($backendGroupId, $accountId, $membershipTypeArray) {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName());

		if (!is_null($backendGroupId) && !is_null($accountId)) {
			// Both backend_group_id and account_id predicates are specified
			$qb->where($qb->expr()->eq('backend_group_id', $qb->createNamedParameter($backendGroupId)));
			$qb->andWhere($qb->expr()->eq('account_id', $qb->createNamedParameter($accountId)));
		} else if (!is_null($backendGroupId)) {
			// Group predicate backend_group_id specified
			$qb->where($qb->expr()->eq('backend_group_id', $qb->createNamedParameter($backendGroupId)));
		} else if (!is_null($accountId)) {
			// User predicate account_id specified
			$qb->where($qb->expr()->eq('account_id', $qb->createNamedParameter($accountId)));
		} else {
			return false;
		}

		$qb->andWhere($qb->expr()->in('membership_type',
			$qb->createNamedParameter($membershipTypeArray, IQueryBuilder::PARAM_INT_ARRAY)));

		return $this->getAffectedQuery($qb);
	}

	/*
	 * Return backend group entities for given user id $userId (internal or gid) of which
	 * the user has specific membership type
	 *
	 * @param string|int $userId
	 * @param bool $isInternalUserId
	 * @param int $membershipType
	 *
	 * @return BackendGroup[]
	 */
	private function getBackendGroupsSqlQuery($userId, $isInternalUserId, $membershipType) {
		$qb = $this->db->getQueryBuilder();
		$alias = $this->getTableAlias();
		$qb->select(['g.id', 'g.group_id', 'g.display_name', 'g.backend'])
			->from($this->getTableName(), $alias)
			->innerJoin($alias, $this->groupMapper->getTableName(), 'g', $qb->expr()->eq('g.id', $alias.'.backend_group_id'));

		$qb = $this->applyUserPredicates($qb, $userId, $isInternalUserId);

		// Place predicate on membership_type
		$qb->andWhere($qb->expr()->eq($alias.'.membership_type', $qb->createNamedParameter($membershipType)));

		return $this->getBackendGroupsQuery($qb);
	}

	/**
	 * Return account entities for given group id $groupId (internal or gid) of which
	 * the accounts have specific membership type. If group id is not specified, it will
	 * return result for all groups.
	 *
	 * @param string|int|null $groupId
	 * @param bool $isInternalGroupId
	 * @param int $membershipType
	 * @return Account[]
	 */
	private function getAccountsSqlQuery($groupId, $isInternalGroupId, $membershipType) {
		$qb = $this->db->getQueryBuilder();
		$alias = $this->getTableAlias();
		$qb->select(['a.id','a.user_id', 'a.lower_user_id', 'a.display_name', 'a.email', 'a.last_login', 'a.backend', 'a.state', 'a.quota', 'a.home'])
			->from($this->getTableName(), 'm')
			->innerJoin('m', $this->accountMapper->getTableName(), 'a', $qb->expr()->eq('a.id', 'm.account_id'));

		if (!is_null($groupId)) {
			$qb = $this->applyGroupPredicates($qb, $groupId, $isInternalGroupId);
		}

		// Place predicate on membership_type
		$qb->andWhere($qb->expr()->eq($alias.'.membership_type', $qb->createNamedParameter($membershipType)));

		return $this->getAccountsQuery($qb);
	}

	/**
	 * Search for members which match the pattern and
	 * are users in the group - identified with group id $groupId (internal or gid)
	 *
	 * @param string|int $groupId
	 * @param bool $isInternalGroupId
	 * @param string $pattern
	 * @param integer $limit
	 * @param integer $offset
	 *
	 * @return Account[]
	 */
	private function searchAccountsSqlQuery($groupId, $isInternalGroupId, $pattern, $limit = null, $offset = null) {
		$qb = $this->db->getQueryBuilder();
		$qb->selectAlias('DISTINCT a.id', 'id')
			->addSelect(['a.user_id', 'a.lower_user_id', 'a.display_name', 'a.email', 'a.last_login', 'a.backend', 'a.state', 'a.quota', 'a.home']);

		$qb = $this->searchMembersSqlQuery($qb, $groupId, $isInternalGroupId, $pattern);

		// Order by display_name so we can use limit and offset
		$qb->orderBy('a.display_name');

		if (!is_null($offset)) {
			$qb->setFirstResult($offset);
		}

		if (!is_null($limit)) {
			$qb->setMaxResults($limit);
		}

		return $this->getAccountsQuery($qb);
	}

	/**
	 * Count members which match the pattern and
	 * are users in the group - identified with group id $groupId (internal or gid)
	 *
	 * @param string|int $groupId
	 * @param bool $isInternalGroupId
	 * @param string $pattern
	 * @return int
	 */
	private function countSqlQuery($groupId, $isInternalGroupId, $pattern) {
		$qb = $this->db->getQueryBuilder();

		// We need to use distinct since otherwise we will get duplicated rows for each search term
		$qb->selectAlias($qb->createFunction('COUNT(DISTINCT a.`id`)'), 'count');

		$qb = $this->searchMembersSqlQuery($qb, $groupId, $isInternalGroupId, $pattern);

 		return $this->getCountQuery($qb);
	}

	/**
	 * Search for members which match the pattern and
	 * are users in the group - identified with group id $groupId (internal or gid)
	 *
	 * @param IQueryBuilder $qb
	 * @param string|int $groupId
	 * @param bool $isInternalGroupId
	 * @param string $pattern
	 *
	 * @return IQueryBuilder
	 */
	private function searchMembersSqlQuery($qb, $groupId, $isInternalGroupId, $pattern) {
		$alias = $this->getTableAlias();

		// Optimize query if pattern is an empty string, and we can retrieve information with faster query
		$emptyPattern = empty($pattern) ? true : false;

		$qb->from($this->accountMapper->getTableName(), 'a')
			->innerJoin('a', $this->getTableName(), $alias, $qb->expr()->eq('a.id', $alias.'.account_id'));

		if (!$emptyPattern) {
			$qb->leftJoin('a', $this->termMapper->getTableName(), 't', $qb->expr()->eq('a.id', 't.account_id'));
		}

		$qb = $this->applyGroupPredicates($qb, $groupId, $isInternalGroupId);

		if (!$emptyPattern) {
			// Non empty pattern means that we need to set predicates on parameters
			// and just fetch all users
			$allowMedialSearches = $this->config->getSystemValue('accounts.enable_medial_search', true);
			if ($allowMedialSearches) {
				$parameter = '%' . $this->db->escapeLikeParameter($pattern) . '%';
				$loweredParameter = '%' . $this->db->escapeLikeParameter(strtolower($pattern)) . '%';
			} else {
				$parameter = $this->db->escapeLikeParameter($pattern) . '%';
				$loweredParameter = $this->db->escapeLikeParameter(strtolower($pattern)) . '%';
			}

			$qb->andWhere(
				$qb->expr()->orX(
					$qb->expr()->like('a.lower_user_id', $qb->createNamedParameter($loweredParameter)),
					$qb->expr()->iLike('a.display_name', $qb->createNamedParameter($parameter)),
					$qb->expr()->iLike('a.email', $qb->createNamedParameter($parameter)),
					$qb->expr()->like('t.term', $qb->createNamedParameter($loweredParameter))
				)
			);
		}

		// Place predicate on membership_type
		$qb->andWhere($qb->expr()->eq($alias.'.membership_type', $qb->createNamedParameter(self::MEMBERSHIP_TYPE_GROUP_USER)));

		return $qb;
	}

	/**
	 * @param IQueryBuilder $qb
	 * @return int
	 */
	private function getAffectedQuery($qb) {
		// If affected is equal or more then 1, it means operation was successful
		$affected = $qb->execute();
		return $affected > 0;
	}

	/**
	 * @param IQueryBuilder $qb
	 * @return bool
	 */
	private function getExistsQuery($qb) {
		// First fetch contains exists
		$stmt = $qb->execute();
		$data = $stmt->fetch();
		$stmt->closeCursor();
		return isset($data['exists']);
	}

	/**
	 * @param IQueryBuilder $qb
	 * @return int
	 */
	private function getCountQuery($qb) {
		// First fetch contains count
		$stmt = $qb->execute();
		$data = $stmt->fetch();
		$stmt->closeCursor();
		return intval($data['count']);
	}

	/**
	 * @param IQueryBuilder $qb
	 * @return Account[]
	 */
	private function getAccountsQuery($qb) {
		$stmt = $qb->execute();
		$accounts = [];
		while($attributes = $stmt->fetch()){
			// Map attributes in array to Account
			// Attributes are explicitly specified by SELECT statement
			$account = new Account();
			$account->setId($attributes['id']);
			$account->setUserId($attributes['user_id']);
			$account->setDisplayName($attributes['display_name']);
			$account->setBackend($attributes['backend']);
			$account->setEmail($attributes['email']);
			$account->setQuota($attributes['quota']);
			$account->setHome($attributes['home']);
			$account->setState($attributes['state']);
			$account->setLastLogin($attributes['last_login']);
			$accounts[] = $account;
		}

		$stmt->closeCursor();
		return $accounts;
	}

	/**
	 * @param IQueryBuilder $qb
	 * @return BackendGroup[]
	 */
	private function getBackendGroupsQuery($qb) {
		$stmt = $qb->execute();
		$groups = [];
		while($attributes = $stmt->fetch()){
			// Map attributes in array to BackendGroup
			// Attributes are explicitly specified by SELECT statement
			$group = new BackendGroup();
			$group->setId($attributes['id']);
			$group->setGroupId($attributes['group_id']);
			$group->setDisplayName($attributes['display_name']);
			$group->setBackend($attributes['backend']);
			$groups[] = $group;
		}

		$stmt->closeCursor();
		return $groups;
	}

	/**
	 * @param IQueryBuilder $qb
	 * @param int $id
	 * @param bool $isInternalId
	 * @return IQueryBuilder
	 */
	private function applyUserPredicates($qb, $id, $isInternalId) {
		// Adjust the query depending on availability of accountId
		// to have optimized access
		$alias = $this->getTableAlias();
		if ($isInternalId) {
			// Apply predicate on account_id in memberships table
			$qb->where($qb->expr()->eq($alias.'.account_id', $qb->createNamedParameter($id)));
		} else {
			// We need to join with accounts table, since we miss information on accountId
			$qb->innerJoin($alias, $this->accountMapper->getTableName(), 'a', $qb->expr()->eq('a.id', $alias.'.account_id'));

			// Apply predicate on user_id in accounts table
			$qb->where($qb->expr()->eq('a.user_id', $qb->createNamedParameter($id)));
		}
		return $qb;
	}

	/**
	 * @param IQueryBuilder $qb
	 * @param string $id
	 * @param bool $isInternalId
	 * @return IQueryBuilder
	 */
	private function applyGroupPredicates($qb, $id, $isInternalId) {
		// Adjust the query depending on availability of accountId
		// to have optimized access
		$alias = $this->getTableAlias();
		if ($isInternalId) {
			// Apply predicate on backend_group_id in memberships table
			$qb->where($qb->expr()->eq($alias.'.backend_group_id', $qb->createNamedParameter($id)));
		} else {
			// We need to join with backend group table, since we miss information on backendGroupId
			$qb->innerJoin($alias, $this->groupMapper->getTableName(), 'g', $qb->expr()->eq('g.id', $alias.'.backend_group_id'));

			// Apply predicate on group_id in backend groups table
			$qb->where($qb->expr()->eq('g.group_id', $qb->createNamedParameter($id)));
		}
		return $qb;
	}
}